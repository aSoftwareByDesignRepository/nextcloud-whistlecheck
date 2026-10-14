<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\ConfigService;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\NotificationService;
use OCA\WhistleCheck\Service\ReminderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReminderServiceTest extends TestCase
{
	private WcaseMapper&MockObject $cases;
	private DeadlineService&MockObject $deadlines;
	private ConfigService&MockObject $config;
	private NotificationService&MockObject $notify;
	private AuditService&MockObject $audit;
	private ReminderService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->cases = $this->createMock(WcaseMapper::class);
		$this->deadlines = $this->createMock(DeadlineService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->notify = $this->createMock(NotificationService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->deadlines->method('today')->willReturn(new \DateTime('2026-02-01', new \DateTimeZone('UTC')));
		$this->config->method('getInt')->willReturnCallback(
			fn (string $k) => $k === 'reminder_days' ? 14 : 0
		);
		$this->svc = new ReminderService($this->cases, $this->deadlines, $this->config, $this->notify, $this->audit);
	}

	private function case(int $id): Wcase
	{
		$c = new Wcase();
		$c->setId($id);
		$c->setStatus(Wcase::STATUS_IN_REVIEW);
		$c->setRevision(3);
		return $c;
	}

	public function testDueSoonCaseIsRemindedOnce(): void
	{
		$case = $this->case(10);
		$this->cases->method('findDueSoon')->willReturn([$case]);
		$this->cases->method('findOverdue')->willReturn([]);
		$this->cases->expects($this->once())->method('updateWithRevision')
			->with($case, 3)->willReturn(1);
		$this->notify->expects($this->once())->method('notifyOfficers')
			->with('wchk.due_soon', 10);
		$this->audit->expects($this->once())->method('record')
			->with('reminder_sent', null, 10, ['days' => 14]);

		$out = $this->svc->run();
		$this->assertSame(['reminded' => 1, 'escalated' => 0], $out);
		$this->assertNotNull($case->getReminderSentAt());
	}

	public function testLostCasRaceSkipsNotificationAndCount(): void
	{
		$case = $this->case(11);
		$this->cases->method('findDueSoon')->willReturn([$case]);
		$this->cases->method('findOverdue')->willReturn([]);
		$this->cases->method('updateWithRevision')->willReturn(0); // CAS lost
		$this->notify->expects($this->never())->method('notifyOfficers');
		$this->audit->expects($this->never())->method('record');

		$out = $this->svc->run();
		$this->assertSame(['reminded' => 0, 'escalated' => 0], $out);
	}

	public function testOverdueCaseNotifiesAllPlusEscalationUid(): void
	{
		$case = $this->case(12);
		$this->cases->method('findDueSoon')->willReturn([]);
		$this->cases->method('findOverdue')->willReturn([$case]);
		$this->cases->method('updateWithRevision')->willReturn(1);
		$this->config->method('get')->willReturnCallback(
			fn (string $k) => $k === 'escalation_uid' ? 'boss1' : ''
		);
		$subjects = [];
		$this->notify->method('notifyOfficers')->willReturnCallback(
			function (string $s, int $id) use (&$subjects) { $subjects[] = $s; }
		);
		$this->notify->expects($this->once())->method('notifyUids')
			->with(['boss1'], 'wchk.overdue_escalation', 12);

		$out = $this->svc->run();
		$this->assertSame(['reminded' => 0, 'escalated' => 1], $out);
		$this->assertSame(['wchk.overdue'], $subjects);
		$this->assertNotNull($case->getOverdueNotifiedAt());
	}

	public function testOverdueWithoutEscalationUidSendsNoTargetedNotice(): void
	{
		$case = $this->case(13);
		$this->cases->method('findDueSoon')->willReturn([]);
		$this->cases->method('findOverdue')->willReturn([$case]);
		$this->cases->method('updateWithRevision')->willReturn(1);
		$this->config->method('get')->willReturn('');
		$this->notify->expects($this->never())->method('notifyUids');
		$this->notify->expects($this->once())->method('notifyOfficers')->with('wchk.overdue', 13);
		$this->svc->run();
	}
}
