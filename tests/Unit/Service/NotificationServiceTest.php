<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\Officer;
use OCA\WhistleCheck\Db\OfficerMapper;
use OCA\WhistleCheck\Service\NotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
	private IManager&MockObject $manager;
	private OfficerMapper&MockObject $officers;
	private NotificationService $svc;
	/** @var array<int,array{uid:?string,object:?array,subject:?array}> */
	private array $captured;

	protected function setUp(): void
	{
		parent::setUp();
		$this->manager = $this->createMock(IManager::class);
		$this->officers = $this->createMock(OfficerMapper::class);
		$this->svc = new NotificationService($this->manager, $this->officers);
		$this->captured = [];

		// Capture the fluent builder calls per notification instance —
		// PHPUnit mock objects disallow dynamic properties, so record
		// state in a side table keyed by object id.
		$this->manager->method('createNotification')->willReturnCallback(function () {
			$n = $this->createMock(INotification::class);
			$id = spl_object_id($n);
			$this->captured[$id] = ['uid' => null, 'object' => null, 'subject' => null];
			$n->method('setApp')->willReturnSelf();
			$n->method('setUser')->willReturnCallback(function ($u) use ($id, $n) {
				$this->captured[$id]['uid'] = $u;
				return $n;
			});
			$n->method('setDateTime')->willReturnSelf();
			$n->method('setObject')->willReturnCallback(function ($t, $oid) use ($id, $n) {
				$this->captured[$id]['object'] = [$t, $oid];
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function ($s, $p) use ($id, $n) {
				$this->captured[$id]['subject'] = [$s, $p];
				return $n;
			});
			return $n;
		});
	}

	private function officer(string $uid): Officer
	{
		$o = new Officer();
		$o->setUid($uid);
		return $o;
	}

	public function testNotifyOfficersCreatesContentFreeNotificationPerActiveOfficer(): void
	{
		$this->officers->method('findActive')->willReturn([
			$this->officer('anna'), $this->officer('ben'),
		]);
		$notified = [];
		$this->manager->expects($this->exactly(2))->method('notify')
			->willReturnCallback(function (INotification $n) use (&$notified) {
				$notified[] = spl_object_id($n);
			});

		$this->svc->notifyOfficers('wchk.new_report', 42);

		$this->assertCount(2, $notified);
		foreach ($notified as $id) {
			$rec = $this->captured[$id];
			// NN-04: subject is a semantic key only — never content, never
			// reporter data. Params carry just the internal numeric case id.
			$this->assertSame('wchk.new_report', $rec['subject'][0]);
			$this->assertSame(['42'], $rec['subject'][1]);
			$this->assertSame(['case', '42'], $rec['object']);
		}
		$uids = array_map(fn ($id) => $this->captured[$id]['uid'], $notified);
		$this->assertSame(['anna', 'ben'], $uids);
	}

	public function testNotifyUidsOnlyNotifiesSubsetOfActiveOfficers(): void
	{
		$this->officers->method('findActive')->willReturn([
			$this->officer('anna'), $this->officer('ben'), $this->officer('cleo'),
		]);
		$notified = [];
		$this->manager->expects($this->once())->method('notify')
			->willReturnCallback(function (INotification $n) use (&$notified) {
				$notified[] = spl_object_id($n);
			});

		$this->svc->notifyUids(['ben'], 'wchk.overdue_escalation', 7);

		$this->assertSame('ben', $this->captured[$notified[0]]['uid']);
		$this->assertSame('wchk.overdue_escalation', $this->captured[$notified[0]]['subject'][0]);
	}

	public function testNotifyOfficersDoesNothingWhenNoActiveOfficers(): void
	{
		$this->officers->method('findActive')->willReturn([]);
		$this->manager->expects($this->never())->method('notify');
		$this->svc->notifyOfficers('wchk.new_report', 1);
	}
}
