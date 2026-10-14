<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\Escalation;
use OCA\WhistleCheck\Db\EscalationMapper;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\EscalationService;
use OCA\WhistleCheck\Service\FacadeProbeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * FC-WCHK-EXPORT consumer side. The fake facade mimics the FacadeResult
 * contract ({ok, code, message, data}) so the boundary mapping is tested
 * without loading TicketCheck classes.
 */
final class EscalationServiceTest extends TestCase
{
	private EscalationMapper&MockObject $mapper;
	private FacadeProbeService&MockObject $probe;
	private AuditService&MockObject $audit;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(EscalationMapper::class);
		$this->probe = $this->createMock(FacadeProbeService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-10-16 12:00:00', new \DateTimeZone('UTC')));
	}

	private function svc(?callable $facadeFactory = null): EscalationService
	{
		return new EscalationService(
			$this->mapper,
			$this->probe,
			$this->audit,
			$this->time,
			$facadeFactory,
			fn (array $payload): object => (object)$payload,
		);
	}

	private function facadeOk(int $ticketId = 42, string $num = 'TC-1042'): object
	{
		return new class ($ticketId, $num) {
			public function __construct(private int $id, private string $num)
			{
			}
			public function createTicket(object $req): object
			{
				return (object)['ok' => true, 'code' => null, 'message' => null, 'data' => ['ticketId' => $this->id, 'ticketNumber' => $this->num]];
			}
		};
	}

	private function facadeFail(string $code, ?array $data = null): object
	{
		return new class ($code, $data) {
			public function __construct(private string $code, private ?array $data)
			{
			}
			public function createTicket(object $req): object
			{
				return (object)['ok' => false, 'code' => $this->code, 'message' => 'nope', 'data' => $this->data];
			}
		};
	}

	private function validParams(array $over = []): array
	{
		return $over + [
			'title' => 'Suspected policy violation',
			'description' => 'Details written by the officer.',
		];
	}

	private function insertedEscalation(): Escalation
	{
		$e = new Escalation();
		$e->setId(5);
		$e->setCaseId(9);
		$e->setOfficerUid('alice');
		$e->setTicketApp('ticketcheck');
		$e->setTicketId(42);
		$e->setTicketNumber('TC-1042');
		$e->setCreatedAt(new \DateTime('2026-10-16 12:00:00', new \DateTimeZone('UTC')));
		return $e;
	}

	public function testHappyPathPersistsAndAudits(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);
		$this->mapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (Escalation $e): bool =>
				$e->getCaseId() === 9
				&& $e->getOfficerUid() === 'alice'
				&& $e->getTicketApp() === 'ticketcheck'
				&& $e->getTicketId() === 42
				&& $e->getTicketNumber() === 'TC-1042'
				&& $e->getIdemKey() === null))
			->willReturn($this->insertedEscalation());
		$this->audit->expects($this->once())
			->method('record')
			->with('case.escalated', 'alice', 9, $this->callback(static fn (array $p): bool =>
				$p['to'] === 'ticketcheck' && $p['key'] === 'TC-1042'));

		$out = $this->svc(fn () => $this->facadeOk())->create(9, 'alice', $this->validParams());

		$this->assertFalse($out['replayed']);
		$this->assertSame('TC-1042', $out['escalation']['ticket_number']);
		$this->assertSame(42, $out['escalation']['ticket_id']);
	}

	public function testOfficerUidComesFromGuardNotParams(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);
		$this->mapper->method('insert')->willReturn($this->insertedEscalation());

		$captured = null;
		$svc = new EscalationService(
			$this->mapper, $this->probe, $this->audit, $this->time,
			fn () => $this->facadeOk(),
			function (array $payload) use (&$captured): object {
				$captured = $payload;
				return (object)$payload;
			},
		);
		// A hostile body tries to smuggle a different actor — the guard uid wins.
		$out = $svc->create(9, 'alice', $this->validParams() + ['actorUid' => 'mallory']);
		$this->assertSame('alice', $captured['actorUid']);
	}

	public function testIdemKeyReplaySkipsFacade(): void
	{
		$existing = $this->insertedEscalation();
		$this->mapper->method('findByIdemKey')->with(9, 'key-12345')->willReturn($existing);
		$this->probe->expects($this->never())->method('escalationTargetUsable');
		$this->mapper->expects($this->never())->method('insert');

		$out = $this->svc(fn () => $this->facadeOk())->create(9, 'alice', $this->validParams(['idemKey' => 'key-12345']));

		$this->assertTrue($out['replayed']);
		$this->assertSame('TC-1042', $out['escalation']['ticket_number']);
	}

	public function testUniqueRaceConvergesOnExistingRow(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);
		$this->mapper->method('findByIdemKey')
			->willReturnOnConsecutiveCalls(null, $this->insertedEscalation());
		$this->mapper->method('insert')->willThrowException(new \Exception('unique violation'));
		$this->audit->expects($this->never())->method('record');

		$out = $this->svc(fn () => $this->facadeOk())->create(9, 'alice', $this->validParams(['idemKey' => 'key-12345']));

		$this->assertTrue($out['replayed']);
	}

	public function testInsertFailureAfterExportStillAuditsThenFails(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);
		$this->mapper->method('insert')->willThrowException(new \Exception('disk full'));

		$this->audit->expects($this->once())
			->method('record')
			->with('case.escalated', 'alice', 9, $this->callback(static fn (array $p): bool =>
				($p['outcome'] ?? null) === 'untracked' && $p['key'] === 'TC-1042'));

		try {
			$this->svc(fn () => $this->facadeOk())->create(9, 'alice', $this->validParams());
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(500, $e->httpStatus);
		}
	}

	public function testValidationAggregatesAllFieldErrors(): void
	{
		try {
			$this->svc()->create(9, 'alice', [
				'title' => '',
				'description' => '',
				'category' => 'nonsense',
				'priority' => 'nonsense',
				'idemKey' => 'bad key with spaces!',
			]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$f = $e->getFields();
			$this->assertSame(['title', 'description', 'category', 'priority', 'idemKey'], array_keys($f));
		}
	}

	public function testPerCaseCapBlocksEscalation(): void
	{
		$this->mapper->method('countForCase')->willReturn(20);
		$this->probe->expects($this->never())->method('escalationTargetUsable');

		try {
			$this->svc()->create(9, 'alice', $this->validParams());
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('escalation_limit', $e->errorCode);
			$this->assertSame(429, $e->httpStatus);
		}
	}

	public function testSiblingUnavailableWhenProbeFails(): void
	{
		$this->mapper->method('countForCase')->willReturn(0);
		$this->probe->method('escalationTargetUsable')->willReturn(false);

		try {
			$this->svc(fn () => $this->facadeOk())->create(9, 'alice', $this->validParams());
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('sibling_unavailable', $e->errorCode);
			$this->assertSame(503, $e->httpStatus);
		}
		$this->mapper->expects($this->never())->method('insert');
	}

	public function testFacadeValidationMapsToFieldErrors(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);

		try {
			$this->svc(fn () => $this->facadeFail('validation_failed', ['fields' => ['title']]))
				->create(9, 'alice', $this->validParams());
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('title', $e->getFields());
		}
	}

	public function testFacadePermissionDeniedMapsTo403(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);

		try {
			$this->svc(fn () => $this->facadeFail('permission_denied'))
				->create(9, 'alice', $this->validParams());
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(403, $e->httpStatus);
		}
	}

	public function testFacadeOtherFailureMapsTo502(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);

		try {
			$this->svc(fn () => $this->facadeFail('internal_error'))
				->create(9, 'alice', $this->validParams());
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(502, $e->httpStatus);
		}
	}

	public function testFacadeThrowMapsToSiblingUnavailable(): void
	{
		$this->probe->method('escalationTargetUsable')->willReturn(true);
		$this->mapper->method('countForCase')->willReturn(0);
		$throwing = new class {
			public function createTicket(object $req): object
			{
				throw new \RuntimeException('di exploded');
			}
		};

		try {
			$this->svc(fn () => $throwing)->create(9, 'alice', $this->validParams());
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('sibling_unavailable', $e->errorCode);
			$this->assertSame(503, $e->httpStatus);
		}
	}

	public function testListForCaseSerializesNewestFirst(): void
	{
		$this->mapper->method('findForCase')->with(9)->willReturn([$this->insertedEscalation()]);
		$out = $this->svc()->listForCase(9);
		$this->assertCount(1, $out);
		$this->assertSame('TC-1042', $out[0]['ticket_number']);
		$this->assertSame('ticketcheck', $out[0]['ticket_app']);
	}
}
