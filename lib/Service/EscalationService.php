<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\Escalation;
use OCA\WhistleCheck\Db\EscalationMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Integration\SiblingFacadeContracts;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Officer-initiated one-way escalation export (FC-WCHK-EXPORT).
 *
 * The anonymity boundary is enforced structurally: the only fields crossing
 * into the sibling are the ones this service reads from $params — title,
 * description, category, priority — plus actorUid and idempotencyKey for the
 * facade's own guards. There is no code path that could attach a case id,
 * reporter handle, ciphertext, or backlink to the ticket.
 *
 * Ordering: the sibling call completes (and commits) FIRST, the local
 * reference row second. No shared transaction — a local insert failure can
 * orphan a ticket reference, never a half-written ticket. The audit event
 * records the ticket number either way so the export stays provable.
 */
class EscalationService
{
	private const MAX_PER_CASE = 20;
	private const MAX_TITLE_LEN = 200;
	private const MAX_DESCRIPTION_LEN = 20000;
	private const IDEM_KEY_PATTERN = '/^[A-Za-z0-9._\-:]{8,128}$/';

	/** Mirrors the target app's v1 contract — fail closed on unknown values. */
	private const ALLOWED_CATEGORIES = ['technical', 'bug', 'feature', 'general', 'billing'];
	private const ALLOWED_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

	/** @var callable|null */
	private $facadeFactory;
	/** @var callable|null */
	private $requestFactory;

	public function __construct(
		private readonly EscalationMapper $escalations,
		private readonly FacadeProbeService $probe,
		private readonly AuditService $audit,
		private readonly ITimeFactory $timeFactory,
		?callable $facadeFactory = null,
		?callable $requestFactory = null,
	) {
		$this->facadeFactory = $facadeFactory;
		$this->requestFactory = $requestFactory;
	}

	/**
	 * @return list<array{id:int,ticket_app:string,ticket_id:int,ticket_number:string,officer_uid:string,created_at:string}>
	 */
	public function listForCase(int $caseId): array
	{
		$out = [];
		foreach ($this->escalations->findForCase($caseId) as $e) {
			$out[] = $this->serialize($e);
		}
		return $out;
	}

	public function targetUsable(): bool
	{
		return $this->probe->escalationTargetUsable();
	}

	/**
	 * @param array<string,mixed> $params title, description, category?, priority?, idemKey?
	 * @return array{escalation: array<string,mixed>, replayed: bool}
	 */
	public function create(int $caseId, string $officerUid, array $params): array
	{
		$title = trim((string)($params['title'] ?? ''));
		$description = trim((string)($params['description'] ?? ''));
		$category = trim((string)($params['category'] ?? 'general'));
		$priority = trim((string)($params['priority'] ?? 'normal'));
		$idemKey = trim((string)($params['idemKey'] ?? ''));

		$fields = [];
		if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LEN) {
			$fields['title'] = 'Title must be 1–200 characters';
		}
		if ($description === '' || mb_strlen($description) > self::MAX_DESCRIPTION_LEN) {
			$fields['description'] = 'Description must be 1–20000 characters';
		}
		if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
			$fields['category'] = 'Unknown category';
		}
		if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
			$fields['priority'] = 'Unknown priority';
		}
		if ($idemKey !== '' && preg_match(self::IDEM_KEY_PATTERN, $idemKey) !== 1) {
			$fields['idemKey'] = 'Invalid idempotency key';
		}
		if ($fields !== []) {
			throw new ValidationException('VALIDATION', 'Please review the highlighted fields.', $fields);
		}

		// Replay first — a retried request returns its earlier result and
		// never reaches the sibling again.
		if ($idemKey !== '') {
			$existing = $this->escalations->findByIdemKey($caseId, $idemKey);
			if ($existing !== null) {
				return ['escalation' => $this->serialize($existing), 'replayed' => true];
			}
		}

		if ($this->escalations->countForCase($caseId) >= self::MAX_PER_CASE) {
			throw new DomainException('escalation_limit', 'Escalation limit reached for this case.', 429);
		}

		if (!$this->probe->escalationTargetUsable()) {
			throw new DomainException('sibling_unavailable', 'Ticket export is not available on this instance.', 503);
		}

		$result = $this->callFacade($officerUid, [
			'title' => $title,
			'description' => $description,
			'category' => $category,
			'priority' => $priority,
			'idempotencyKey' => $idemKey !== '' ? $idemKey : null,
		]);

		$ticketId = (int)$result['ticketId'];
		$ticketNumber = (string)$result['ticketNumber'];

		$esc = new Escalation();
		$esc->setCaseId($caseId);
		$esc->setOfficerUid($officerUid);
		$esc->setTicketApp(SiblingFacadeContracts::TICKET_CHECK_APP);
		$esc->setTicketId($ticketId);
		$esc->setTicketNumber($ticketNumber);
		$esc->setIdemKey($idemKey !== '' ? $idemKey : null);
		$esc->setCreatedAt($this->timeFactory->getDateTime());

		try {
			$esc = $this->escalations->insert($esc);
		} catch (\Throwable) {
			// Unique-key race: a concurrent same-key request won. Converge on
			// its row — the second ticket the facade may have minted is still
			// keyed in the sibling's own idempotency store, so this can only
			// happen when both sides raced past lookup.
			if ($idemKey !== '') {
				$existing = $this->escalations->findByIdemKey($caseId, $idemKey);
				if ($existing !== null) {
					return ['escalation' => $this->serialize($existing), 'replayed' => true];
				}
			}
			// Ticket exists but the reference row could not be written. Still
			// audit the export — the ticket number is the durable proof and
			// lets an admin correlate manually if ever needed.
			$this->audit->record('case.escalated', $officerUid, $caseId, [
				'to' => SiblingFacadeContracts::TICKET_CHECK_APP,
				'key' => $ticketNumber,
				'outcome' => 'untracked',
			]);
			throw new DomainException('internal', 'Export completed but could not be recorded.', 500);
		}

		$this->audit->record('case.escalated', $officerUid, $caseId, [
			'to' => SiblingFacadeContracts::TICKET_CHECK_APP,
			'key' => $ticketNumber,
		]);

		return ['escalation' => $this->serialize($esc), 'replayed' => false];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ticketId:int|string,ticketNumber:string}
	 */
	private function callFacade(string $officerUid, array $payload): array
	{
		try {
			$facade = $this->facadeFactory !== null
				? ($this->facadeFactory)()
				: \OCP\Server::get(SiblingFacadeContracts::ESCALATION_WRITE_FACADE);

			$request = $this->requestFactory !== null
				? ($this->requestFactory)($payload + ['actorUid' => $officerUid])
				: (function () use ($officerUid, $payload) {
					$class = SiblingFacadeContracts::ESCALATION_WRITE_REQUEST;
					return $class::fromArray($payload + ['actorUid' => $officerUid]);
				})();

			$result = $facade->createTicket($request);
		} catch (DomainException|ValidationException $e) {
			throw $e;
		} catch (\Throwable) {
			throw new DomainException('sibling_unavailable', 'Ticket export is not available on this instance.', 503);
		}

		if ($result->ok) {
			$data = $result->data ?? [];
			if (!isset($data['ticketId'], $data['ticketNumber'])) {
				throw new DomainException('internal', 'Unexpected export response.', 500);
			}
			return $data;
		}

		$code = (string)($result->code ?? 'internal_error');
		if ($code === 'validation_failed') {
			$fields = [];
			foreach ((array)($result->data['fields'] ?? []) as $f) {
				$fields[(string)$f] = (string)($result->message ?? 'Invalid value');
			}
			throw new ValidationException('VALIDATION', 'Please review the highlighted fields.', $fields);
		}
		if ($code === 'permission_denied') {
			throw new DomainException('permission_denied', 'You are not allowed to export tickets.', 403);
		}
		throw new DomainException('export_failed', 'Ticket export failed.', 502);
	}

	/**
	 * @return array{id:int,ticket_app:string,ticket_id:int,ticket_number:string,officer_uid:string,created_at:string}
	 */
	private function serialize(Escalation $e): array
	{
		return [
			'id' => $e->getId(),
			'ticket_app' => $e->getTicketApp(),
			'ticket_id' => $e->getTicketId(),
			'ticket_number' => $e->getTicketNumber(),
			'officer_uid' => $e->getOfficerUid(),
			'created_at' => $e->getCreatedAt()->format('Y-m-d H:i:s'),
		];
	}
}
