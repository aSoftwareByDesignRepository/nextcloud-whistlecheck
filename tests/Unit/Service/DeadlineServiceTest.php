<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Service\DeadlineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class DeadlineServiceTest extends TestCase
{
	private DeadlineService $svc;

	protected function setUp(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('Europe/Berlin');
		$this->svc = new DeadlineService($this->createMock(ITimeFactory::class), $config);
	}

	public static function feedbackDueCases(): array
	{
		return [
			'plain' => ['2026-01-15', '2026-04-15'],
			'month-end clamp Jan31→Apr30' => ['2026-01-31', '2026-04-30'],
			'month-end clamp Mar31→Jun30' => ['2026-03-31', '2026-06-30'],
			'year rollover Nov→Feb' => ['2026-11-30', '2027-02-28'],
			'leap year Nov→Feb29' => ['2027-11-30', '2028-02-29'],
			'Dec 31 rollover' => ['2026-12-31', '2027-03-31'],
			'Aug 31 → Nov 30 clamp' => ['2026-08-31', '2026-11-30'],
		];
	}

	/** @dataProvider feedbackDueCases */
	public function testFeedbackDueIsThreeCalendarMonthsClamped(string $in, string $expected): void
	{
		$due = $this->svc->feedbackDue(new \DateTimeImmutable($in, new \DateTimeZone('Europe/Berlin')));
		$this->assertSame($expected, $due->format('Y-m-d'));
	}

	public function testRetentionUntilClampsSameWay(): void
	{
		$due = new \DateTimeImmutable('2026-01-31', new \DateTimeZone('Europe/Berlin'));
		$ret = $this->svc->retentionUntil($due, 36);
		$this->assertSame('2029-01-31', $ret->format('Y-m-d'));

		// 36 months from Feb 29 → Feb 28 (clamp, not Mar 1)
		$due = new \DateTimeImmutable('2024-02-29', new \DateTimeZone('Europe/Berlin'));
		$this->assertSame('2027-02-28', $this->svc->retentionUntil($due, 36)->format('Y-m-d'));
	}

	public function testReminderDay(): void
	{
		$due = new \DateTimeImmutable('2026-06-15', new \DateTimeZone('Europe/Berlin'));
		$this->assertSame('2026-06-01', $this->svc->reminderDay($due, 14)->format('Y-m-d'));
		$this->assertSame('2026-06-15', $this->svc->reminderDay($due, 0)->format('Y-m-d'));
	}

	public function testIsDueBoundary(): void
	{
		$due = new \DateTimeImmutable('2026-06-15');
		$this->assertTrue($this->svc->isDue($due, new \DateTimeImmutable('2026-06-15')));
		$this->assertTrue($this->svc->isDue($due, new \DateTimeImmutable('2026-06-16')));
		$this->assertFalse($this->svc->isDue($due, new \DateTimeImmutable('2026-06-14')));
	}

	public function testIsOverdueStrictlyAfterDueDate(): void
	{
		$due = new \DateTimeImmutable('2026-06-15');
		$this->assertFalse($this->svc->isOverdue($due, new \DateTimeImmutable('2026-06-15')));
		$this->assertTrue($this->svc->isOverdue($due, new \DateTimeImmutable('2026-06-16')));
	}

	public function testInvalidTimezoneFallsBack(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('Not/AZone');
		$svc = new DeadlineService($this->createMock(ITimeFactory::class), $config);
		$this->assertSame('Europe/Berlin', $svc->orgTz()->getName());
	}
}
