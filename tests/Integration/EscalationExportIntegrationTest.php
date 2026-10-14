<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Integration;

use OCA\WhistleCheck\Db\EscalationMapper;
use OCA\WhistleCheck\Service\EscalationService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * FC-WCHK-EXPORT end-to-end inside one Nextcloud: WhistleCheck service →
 * real TicketCheck facade → real ticket row. Asserts the anonymity
 * boundary (only officer-authored text crosses), idempotent replay, and
 * that the escalation reference dies with the case.
 */
final class EscalationExportIntegrationTest extends TestCase
{
	private const UID = 'wchk-esc-it';

	private IDBConnection $db;
	private ?IUser $user = null;
	private ?int $caseId = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
		/** @var IUserManager $users */
		$users = \OC::$server->get(IUserManager::class);
		$this->user = $users->get(self::UID) ?? $users->createUser(self::UID, 'xA9!' . bin2hex(random_bytes(8)));
		self::assertNotNull($this->user);
		// Clean session state — the test exercises the sessionless
		// actor path through the facade.
		\OC::$server->get(IUserSession::class)->setUser(null);
	}

	protected function tearDown(): void
	{
		if ($this->caseId !== null) {
			$this->deleteWhere('wchk_escalations', 'case_id', $this->caseId);
			$this->deleteWhere('wchk_cases', 'id', $this->caseId);
			// wchk_audit is intentionally NOT cleaned: it is a hash chain —
			// deleting a mid-chain event would corrupt verifyChain. Test
			// events against a deleted case mirror real retention output.
		}
		$this->deleteWhere('helpdesk_tickets', 'created_by', self::UID);
		$this->deleteWhere('tc_idempotency', 'user_id', self::UID);
		if ($this->user !== null) {
			$this->user->delete();
		}
		parent::tearDown();
	}

	private function deleteWhere(string $table, string $col, int|string $val): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($val)));
		$qb->executeStatement();
	}

	private function countWhere(string $table, string $col, int|string $val): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($val)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function makeCase(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('wchk_cases')->values([
			'lookup_hint' => $qb->createNamedParameter(bin2hex(random_bytes(16))),
			'code_hash' => $qb->createNamedParameter(hash('sha256', 'it-code')),
			'code_salt' => $qb->createNamedParameter(bin2hex(random_bytes(8))),
			'key_salt' => $qb->createNamedParameter(bin2hex(random_bytes(8))),
			'reporter_wrap' => $qb->createNamedParameter('sealed-wrap'),
			'status' => $qb->createNamedParameter('received'),
			'received_at' => $qb->createNamedParameter('2026-10-16 08:00:00'),
			'feedback_due' => $qb->createNamedParameter('2026-11-15'),
		]);
		$qb->executeStatement();
		$this->caseId = (int)$this->db->lastInsertId('oc_wchk_cases');
		return $this->caseId;
	}

	private function svc(): EscalationService
	{
		return \OC::$server->get(EscalationService::class);
	}

	public function testFullRoundtripCreatesRealTicketWithoutCaseLeakage(): void
	{
		$caseId = $this->makeCase();

		$out = $this->svc()->create($caseId, self::UID, [
			'title' => 'Escalation: facility concern',
			'description' => 'Officer summary text — deliberately contains no case id.',
			'category' => 'technical',
			'priority' => 'high',
			'idemKey' => 'it-key-' . bin2hex(random_bytes(6)),
		]);

		self::assertFalse($out['replayed']);
		$esc = $out['escalation'];
		self::assertSame('ticketcheck', $esc['ticket_app']);
		self::assertNotSame('', $esc['ticket_number']);

		// The ticket really exists in TicketCheck's own table…
		$qb = $this->db->getQueryBuilder();
		$qb->select('title', 'description', 'category', 'priority', 'created_by', 'customer_email')
			->from('helpdesk_tickets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($esc['ticket_id'])));
		$row = $qb->executeQuery()->fetch();
		self::assertNotFalse($row);
		self::assertSame('Escalation: facility concern', $row['title']);
		self::assertSame(self::UID, $row['created_by']);
		self::assertSame('technical', $row['category']);
		self::assertSame('high', $row['priority']);
		// Test user has no email → the facade minted the provenance placeholder.
		self::assertStringEndsWith('@whistlecheck.invalid', (string)$row['customer_email']);

		// …and NOTHING case-shaped crossed the boundary: no case id, no
		// lookup hint, no ciphertext anywhere in the stored ticket text.
		$haystack = $row['title'] . ' ' . $row['description'];
		self::assertStringNotContainsString((string)$caseId, $haystack);
		self::assertStringNotContainsString('sealed-wrap', $haystack);

		// Impersonation was restored — no bound session leaks out.
		self::assertNull(\OC::$server->get(IUserSession::class)->getUser());

		// Escalation row persisted and listable.
		$rows = $this->svc()->listForCase($caseId);
		self::assertCount(1, $rows);
		self::assertSame($esc['ticket_number'], $rows[0]['ticket_number']);

		// Audit chain carries the export event with the ticket number —
		// and nothing content-shaped.
		$qb = $this->db->getQueryBuilder();
		$qb->select('payload_json')->from('wchk_audit')
			->where($qb->expr()->eq('case_id', $qb->createNamedParameter($caseId)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter('case.escalated')));
		$payload = (string)$qb->executeQuery()->fetchOne();
		self::assertStringContainsString($esc['ticket_number'], $payload);
		self::assertStringNotContainsString('facility concern', $payload, 'audit must stay content-free');
	}

	public function testIdempotentReplayMintsNoSecondTicket(): void
	{
		$caseId = $this->makeCase();
		$params = [
			'title' => 'First export',
			'description' => 'Body',
			'idemKey' => 'it-key-' . bin2hex(random_bytes(6)),
		];

		$first = $this->svc()->create($caseId, self::UID, $params);
		$second = $this->svc()->create($caseId, self::UID, $params);

		self::assertFalse($first['replayed']);
		self::assertTrue($second['replayed']);
		self::assertSame($first['escalation']['ticket_id'], $second['escalation']['ticket_id']);

		self::assertSame(1, $this->countWhere('helpdesk_tickets', 'created_by', self::UID), 'replay must not mint a second ticket');
		self::assertSame(1, $this->countWhere('wchk_escalations', 'case_id', $caseId));
	}

	public function testDeleteForCaseRemovesEscalationRows(): void
	{
		$caseId = $this->makeCase();
		$this->svc()->create($caseId, self::UID, [
			'title' => 'To be shredded',
			'description' => 'Body',
		]);

		$escMapper = \OC::$server->get(EscalationMapper::class);
		self::assertCount(1, $escMapper->findForCase($caseId));

		$escMapper->deleteForCase($caseId);
		self::assertCount(0, $escMapper->findForCase($caseId));
	}
}
