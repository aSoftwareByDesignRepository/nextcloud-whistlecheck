<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * §34 HinSchG deadline arithmetic (spec US-003).
 *
 *   receipt confirmation : immediate (same transaction as intake)
 *   feedback deadline    : calendar +3 months, day-clamped to month end
 *                          (Jan 31 → Apr 30, Mar 31 → Jun 30)
 *   reminders            : org-configured days before due date
 *   overdue escalation   : due date passed without feedback
 *
 * Dates are DATE-only, computed in the organization timezone so "due" means
 * "end of that local day", not a UTC instant. Month-end clamping is required:
 * PHP's native +3 months overflows (Jan 31 + 3M = May 1) — we clamp instead.
 */
class DeadlineService
{
	public function __construct(
		private readonly ITimeFactory $timeFactory,
		private readonly IConfig $config,
	) {
	}

	/**
	 * +3 calendar months, clamped to the target month's last day.
	 * Jan 31 → Apr 30;  Mar 31 → Jun 30;  Nov 30 → Feb 28/29.
	 */
	public function feedbackDue(\DateTimeInterface $receivedAt): \DateTime
	{
		$tz = $this->orgTz();
		$base = (new \DateTime($receivedAt->format('Y-m-d'), $tz));
		$y = (int)$base->format('Y');
		$m = (int)$base->format('n');
		$d = (int)$base->format('j');

		$m += 3;
		$y += intdiv($m - 1, 12);
		$m = (($m - 1) % 12) + 1;

		$lastDay = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz))
			->format('t');
		return new \DateTime(sprintf('%04d-%02d-%02d', $y, $m, min($d, $lastDay)), $tz);
	}

	/**
	 * Retention = closed_at + configured months (default 36 = 3 years per
	 * spec §retention; org-configurable within bounds enforced by ConfigService).
	 * Called with the close timestamp by CaseService::setStatus().
	 */
	public function retentionUntil(\DateTimeInterface $from, int $months): \DateTime
	{
		$tz = $this->orgTz();
		$y = (int)$from->format('Y');
		$m = (int)$from->format('n') + $months;
		$d = (int)$from->format('j');
		$y += intdiv($m - 1, 12);
		$m = (($m - 1) % 12) + 1;
		$lastDay = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz))
			->format('t');
		return new \DateTime(sprintf('%04d-%02d-%02d', $y, $m, min($d, $lastDay)), $tz);
	}

	/**
	 * Reminder day = due - N days (N from config, default 14).
	 */
	public function reminderDay(\DateTimeInterface $due, int $daysBefore = 14): \DateTime
	{
		$d = new \DateTime($due->format('Y-m-d'), $this->orgTz());
		$d->modify('-' . max(0, $daysBefore) . ' days');
		return $d;
	}

	public function isDue(\DateTimeInterface $due, ?\DateTimeInterface $now = null): bool
	{
		$today = ($now ?? new \DateTime('now', $this->orgTz()))->format('Y-m-d');
		return $due->format('Y-m-d') <= $today;
	}

	public function isOverdue(\DateTimeInterface $due, ?\DateTimeInterface $now = null): bool
	{
		$today = ($now ?? new \DateTime('now', $this->orgTz()))->format('Y-m-d');
		return $due->format('Y-m-d') < $today;
	}

	public function orgTz(): \DateTimeZone
	{
		$tz = $this->config->getSystemValueString('default_timezone', 'Europe/Berlin');
		try {
			return new \DateTimeZone($tz);
		} catch (\Throwable) {
			return new \DateTimeZone('Europe/Berlin');
		}
	}

	public function today(): \DateTime
	{
		return new \DateTime('now', $this->orgTz());
	}
}
