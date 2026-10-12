<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Crypto;

use OCA\WhistleCheck\Crypto\CaseCodeService;
use PHPUnit\Framework\TestCase;

final class CaseCodeServiceTest extends TestCase
{
	private CaseCodeService $svc;

	protected function setUp(): void
	{
		$this->svc = new CaseCodeService();
	}

	public function testGenerateProducesFormattedCodeAnd16Bytes(): void
	{
		$gen = $this->svc->generate();
		$this->assertMatchesRegularExpression('/^[0-9A-Z]{5}(-[0-9A-Z]{5}){4}-[0-9A-Z]$/', $gen['code']);
		$this->assertSame(16, strlen($gen['bytes']));
	}

	public function testGenerateIsUnique(): void
	{
		$a = $this->svc->generate();
		$b = $this->svc->generate();
		$this->assertNotSame($a['code'], $b['code']);
	}

	public function testEncodeRejectsWrongByteLength(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->encode('too short');
	}

	public function testEncodeUsesOnlyCrockfordAlphabet(): void
	{
		$canonical = $this->svc->encode(random_bytes(16));
		$this->assertSame(26, strlen($canonical));
		$this->assertDoesNotMatchRegularExpression('/[ILOU]/i', $canonical);
	}

	public function testEncodeDecodeRoundtrip(): void
	{
		$bytes = random_bytes(16);
		$this->assertSame($bytes, $this->svc->decode($this->svc->encode($bytes)));
	}

	public function testNormalizeCaseInsensitiveAndSeparators(): void
	{
		$gen = $this->svc->generate();
		$lower = strtolower($gen['code']);
		$this->assertSame(26, strlen($this->svc->normalize($lower)));
		$this->assertSame(
			$this->svc->normalize($lower),
			$this->svc->normalize(str_replace('-', ' ', $gen['code']))
		);
		$this->assertSame(
			$this->svc->normalize($lower),
			$this->svc->normalize(str_replace('-', '', $gen['code']))
		);
	}

	public function testNormalizeMapsConfusables(): void
	{
		// O→0, I/L→1 — "OoIl" normalizes to "0001" prefix region
		$canonical = $this->svc->normalize('OoIl' . str_repeat('2', 22));
		$this->assertSame('0011', substr($canonical, 0, 4));
	}

	public function testNormalizeRejectsBadLength(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->normalize('ABC');
	}

	public function testNormalizeRejectsForbiddenChars(): void
	{
		// U is excluded from the Crockford alphabet
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->normalize('UUUUU-UUUUU-UUUUU-UUUUU-UUUUU-U');
	}

	public function testDecodeLooseReturnsNullOnGarbage(): void
	{
		$this->assertNull($this->svc->decodeLoose('not a code'));
		$this->assertNull($this->svc->decodeLoose(''));
		$this->assertNotNull($this->svc->decodeLoose($this->svc->generate()['code']));
	}

	public function testLookupHintDeterministicAndHex64(): void
	{
		$gen = $this->svc->generate();
		$h1 = $this->svc->lookupHint($gen['bytes']);
		$this->assertSame($h1, $this->svc->lookupHint($gen['bytes']));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1);
	}

	public function testLookupHintDiffersAcrossCodes(): void
	{
		$a = $this->svc->generate();
		$b = $this->svc->generate();
		$this->assertNotSame($this->svc->lookupHint($a['bytes']), $this->svc->lookupHint($b['bytes']));
	}

	public function testVerifierVerifiesAndRejectsWrong(): void
	{
		$gen = $this->svc->generate();
		$salt = $this->svc->newSalt();
		$verifier = $this->svc->verifier($gen['bytes'], $salt);
		$stored = hash('sha256', $verifier); // code_hash stores sha256(verifier)
		$this->assertTrue($this->svc->verify($verifier, $stored));
		// The stored digest is NOT a replayable credential.
		$this->assertFalse($this->svc->verify($stored, $stored));
		$other = $this->svc->generate();
		$this->assertFalse($this->svc->verify($this->svc->verifier($other['bytes'], $salt), $stored));
	}

	public function testVerifierRejectsBadSalt(): void
	{
		$gen = $this->svc->generate();
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->verifier($gen['bytes'], 'zz');
	}

	public function testReporterKeyDiffersFromVerifierDomain(): void
	{
		$gen = $this->svc->generate();
		$salt = $this->svc->newSalt();
		$verifier = hex2bin($this->svc->verifier($gen['bytes'], $salt));
		$key = $this->svc->reporterKey($gen['bytes'], $salt);
		$this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
		$this->assertNotSame($verifier, $key);
	}

	public function testPseudoSaltDeterministicAndWellFormed(): void
	{
		$hint = str_repeat('ab', 32);
		$s1 = $this->svc->pseudoSalt($hint, 'secret');
		$this->assertSame($s1, $this->svc->pseudoSalt($hint, 'secret'));
		$this->assertSame(32, strlen($s1));
		$this->assertNotSame($s1, $this->svc->pseudoSalt($hint, 'other-secret'));
		$this->assertNotSame($s1, $this->svc->pseudoSalt(str_repeat('cd', 32), 'secret'));
	}
}
