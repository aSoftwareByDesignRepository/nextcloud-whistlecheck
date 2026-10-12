<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\CategoryMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Db\WcaseMapper;

/**
 * Aggregate statistics (spec US-016 / NH-04). Counts only — no content, no
 * identifiers. Buckets below the small-cell floor are suppressed so a lone
 * case in a rare category cannot be singled out (spec: no per-case detail).
 */
class StatsService
{
	private const SMALL_CELL = 5;

	public function __construct(
		private readonly WcaseMapper $cases,
		private readonly CategoryMapper $categories,
		private readonly DeadlineService $deadlines,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function dashboard(): array
	{
		$today = $this->deadlines->today();
		$byStatus = array_fill_keys(Wcase::STATUSES, 0);
		$byCategory = [];
		$overdue = 0;
		$inbox = $this->cases->listInbox(10000, 0);

		foreach ($inbox as $case) {
			$byStatus[$case->getStatus()] = ($byStatus[$case->getStatus()] ?? 0) + 1;
			if ($case->isOverdue($today)) {
				$overdue++;
			}
			$cid = $case->getCategoryId();
			if ($cid !== null) {
				$byCategory[$cid] = ($byCategory[$cid] ?? 0) + 1;
			}
		}

		// Small-cell suppression on the category dimension.
		$categoryOut = [];
		foreach ($this->categories->findAll() as $cat) {
			$n = $byCategory[$cat->getId()] ?? 0;
			$categoryOut[] = [
				'id' => $cat->getId(),
				'label' => json_decode((string)$cat->getLabelJson(), true) ?: [],
				'count' => $n >= self::SMALL_CELL ? $n : null, // null = suppressed
			];
		}

		return [
			'generated_at' => (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM),
			'total' => array_sum($byStatus),
			'by_status' => $byStatus,
			'overdue' => $overdue,
			'by_category' => $categoryOut,
			'small_cell_floor' => self::SMALL_CELL,
		];
	}
}
