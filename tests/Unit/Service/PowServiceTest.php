<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Service\PowService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class PowServiceTest extends TestCase
{
	private PowService $svc;
	private ITimeFactory $time;

	protected function setUp(): void
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('fixed-test-secret');
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
		$this->svc = new PowService($appConfig, $this->time);
	}

	private function solve(string $challengeB64, int $difficulty): string
	{
		$challenge = base64_decode($challengeB64, true);
		for ($i = 0; $i < 10_000_000; $i++) {
			$nonce = random_bytes(16);
			$digest = hash('sha256', $challenge . $nonce, true);
			if ($this->leadingZeros($digest) >= $difficulty) {
				return base64_encode($nonce);
			}
		}
		$this->fail('could not solve PoW');
	}

	private function leadingZeros(string $bytes): int
	{
		$bits = 0;
		foreach (str_split($bytes) as $b) {
			$v = ord($b);
			for ($m = 7; $m >= 0; $m--) {
				if (($v >> $m) & 1) {
					return $bits;
				}
				$bits++;
			}
		}
		return $bits;
	}

	public function testIssueShape(): void
	{
		$c = $this->svc->issue('bucket-x');
		$this->assertArrayHasKey('challenge', $c);
		$this->assertArrayHasKey('difficulty', $c);
		$this->assertArrayHasKey('expires', $c);
		$this->assertSame(PowService::DIFFICULTY, $c['difficulty']);
		$this->assertNotFalse(base64_decode($c['challenge'], true));
	}

	public function testChallengeDiffersPerBucket(): void
	{
		$this->assertNotSame($this->svc->issue('a')['challenge'], $this->svc->issue('b')['challenge']);
	}

	public function testVerifyAcceptsCorrectSolution(): void
	{
		$bucket = 'bucket-' . bin2hex(random_bytes(4));
		$c = $this->svc->issue($bucket);
		$nonce = $this->solve($c['challenge'], 8); // low difficulty for speed
		$this->assertTrue($this->svc->verify($bucket, $c['challenge'], $nonce, 8));
	}

	public function testVerifyRejectsWrongNonce(): void
	{
		$bucket = 'bucket-' . bin2hex(random_bytes(4));
		$c = $this->svc->issue($bucket);
		// A random nonce almost certainly fails difficulty 20
		$this->assertFalse($this->svc->verify($bucket, $c['challenge'], base64_encode(str_repeat("\xFF", 16)), 20));
	}

	public function testVerifyRejectsChallengeForOtherBucket(): void
	{
		$c = $this->svc->issue('bucket-a');
		$nonce = $this->solve($c['challenge'], 8);
		$this->assertFalse($this->svc->verify('bucket-b', $c['challenge'], $nonce, 8));
	}

	public function testVerifyRejectsForgedChallenge(): void
	{
		$this->assertFalse($this->svc->verify('bucket-a', base64_encode('forged'), base64_encode(random_bytes(16)), 8));
	}

	public function testVerifyRejectsMalformedInputs(): void
	{
		$c = $this->svc->issue('b');
		$this->assertFalse($this->svc->verify('b', '!!!', base64_encode(random_bytes(16))));
		$this->assertFalse($this->svc->verify('b', $c['challenge'], '!!!'));
		$this->assertFalse($this->svc->verify('b', $c['challenge'], base64_encode('ab'))); // nonce < 4 bytes
		$this->assertFalse($this->svc->verify('b', $c['challenge'], base64_encode(random_bytes(128)))); // nonce > 64
	}

	public function testPreviousSlotAcceptedCurrentBeyondRejected(): void
	{
		$bucket = 'tb';
		// Challenge issued at slot t-1 should still verify at slot t.
		$time1 = $this->createMock(ITimeFactory::class);
		$time1->method('getTime')->willReturn(1_800_000_000);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('s');
		$svc1 = new PowService($appConfig, $time1);
		$c = $svc1->issue($bucket);

		$time2 = $this->createMock(ITimeFactory::class);
		$time2->method('getTime')->willReturn(1_800_000_000 + 300); // next slot
		$svc2 = new PowService($appConfig, $time2);
		$nonce = $this->solve($c['challenge'], 8);
		$this->assertTrue($svc2->verify($bucket, $c['challenge'], $nonce, 8));

		// Two slots ahead — expired
		$time3 = $this->createMock(ITimeFactory::class);
		$time3->method('getTime')->willReturn(1_800_000_000 + 600);
		$svc3 = new PowService($appConfig, $time3);
		$this->assertFalse($svc3->verify($bucket, $c['challenge'], $nonce, 8));
	}
}
