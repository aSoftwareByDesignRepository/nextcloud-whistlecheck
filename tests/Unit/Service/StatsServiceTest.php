<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\Category;
use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;
use OCA\WhistleCheck\Service\DeadlineService;
use OCA\WhistleCheck\Service\StatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatsServiceTest extends TestCase
{
	private WcaseMapper&MockObject $cases;
	private CategoryMapper&MockObject $categories;
	private DeadlineService&MockObject $deadlines;
	private StatsService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->cases = $this->createMock(WcaseMapper::class);
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->deadlines = $this->createMock(DeadlineService::class);
		$this->deadlines->method('today')->willReturn(new \DateTime('2026-02-01', new \DateTimeZone('UTC')));
		$this->svc = new StatsService($this->cases, $this->categories, $this->deadlines);
	}

	private function case(string $status, ?int $categoryId, ?\DateTime $due = null, ?\DateTime $feedbackSent = null): Wcase
	{
		$c = new Wcase();
		$c->setStatus($status);
		$c->setCategoryId($categoryId);
		$c->setFeedbackDue($due ?? new \DateTime('2099-01-01', new \DateTimeZone('UTC')));
		$c->setFeedbackSentAt($feedbackSent);
		return $c;
	}

	private function category(int $id, string $en, string $de): Category
	{
		$cat = new Category();
		$cat->setId($id);
		$cat->setLabelJson(json_encode(['en' => $en, 'de' => $de]));
		return $cat;
	}

	public function testDashboardCountsByStatusAndFlagsOverdue(): void
	{
		$this->cases->method('listInbox')->willReturn([
			$this->case(Wcase::STATUS_RECEIVED, 1),
			$this->case(Wcase::STATUS_IN_REVIEW, 1),
			// Past due + no feedback sent → overdue
			$this->case(Wcase::STATUS_IN_REVIEW, 2, new \DateTime('2026-01-01', new \DateTimeZone('UTC'))),
			// Past due but feedback already sent → NOT overdue
			$this->case(Wcase::STATUS_ACTION_TAKEN, 2, new \DateTime('2026-01-01', new \DateTimeZone('UTC')), new \DateTime('2026-01-15', new \DateTimeZone('UTC'))),
			$this->case(Wcase::STATUS_CLOSED, null),
		]);
		$this->categories->method('findAll')->willReturn([
			$this->category(1, 'Fraud', 'Betrug'),
			$this->category(2, 'Safety', 'Sicherheit'),
		]);

		$d = $this->svc->dashboard();
		$this->assertSame(5, $d['total']);
		$this->assertSame(1, $d['by_status']['received']);
		$this->assertSame(2, $d['by_status']['in_review']);
		$this->assertSame(1, $d['by_status']['action_taken']);
		$this->assertSame(1, $d['by_status']['closed']);
		$this->assertSame(1, $d['overdue']);
	}

	public function testSmallCellSuppressionHidesRareCategories(): void
	{
		$rows = [];
		for ($i = 0; $i < 4; $i++) {
			$rows[] = $this->case(Wcase::STATUS_RECEIVED, 1); // below floor of 5
		}
		for ($i = 0; $i < 5; $i++) {
			$rows[] = $this->case(Wcase::STATUS_RECEIVED, 2); // exactly at floor
		}
		$this->cases->method('listInbox')->willReturn($rows);
		$this->categories->method('findAll')->willReturn([
			$this->category(1, 'Rare', 'Selten'),
			$this->category(2, 'Common', 'Häufig'),
			$this->category(3, 'Empty', 'Leer'),
		]);

		$d = $this->svc->dashboard();
		$this->assertSame(5, $d['small_cell_floor']);
		$byCat = array_column($d['by_category'], 'count', 'id');
		$this->assertNull($byCat[1], 'count below the floor must be suppressed, not revealed');
		$this->assertSame(5, $byCat[2], 'count at the floor is reportable');
		$this->assertNull($byCat[3], 'empty categories stay suppressed');
	}

	public function testDashboardIsEmptyWhenNoCases(): void
	{
		$this->cases->method('listInbox')->willReturn([]);
		$this->categories->method('findAll')->willReturn([]);
		$d = $this->svc->dashboard();
		$this->assertSame(0, $d['total']);
		$this->assertSame(0, $d['overdue']);
		$this->assertSame([], $d['by_category']);
	}
}
