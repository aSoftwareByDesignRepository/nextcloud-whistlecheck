<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Crypto;

use OCA\WhistleCheck\Crypto\EnvelopeService;
use PHPUnit\Framework\TestCase;

final class EnvelopeServiceTest extends TestCase
{
	private EnvelopeService $svc;

	protected function setUp(): void
	{
		$this->svc = new EnvelopeService();
	}

	public function testSealOpenRoundtrip(): void
	{
		$key = $this->svc->randomKey();
		$env = $this->svc->seal('geheimer Text — ünïcode ✓', $key);
		$this->assertTrue($this->svc->isValidEnvelope($env, EnvelopeService::ALG_SECRETBOX));
		$this->assertSame('geheimer Text — ünïcode ✓', $this->svc->open($env, $key));
	}

	public function testOpenWithWrongKeyReturnsNull(): void
	{
		$env = $this->svc->seal('secret', $this->svc->randomKey());
		$this->assertNull($this->svc->open($env, $this->svc->randomKey()));
	}

	public function testOpenRejectsTamperedCiphertext(): void
	{
		$key = $this->svc->randomKey();
		$env = json_decode($this->svc->seal('payload-data', $key), true);
		$ct = base64_decode($env['c'], true);
		$ct[0] = chr(ord($ct[0]) ^ 0xFF); // flip a bit
		$env['c'] = base64_encode($ct);
		$this->assertNull($this->svc->open(json_encode($env), $key));
	}

	public function testOpenRejectsMalformedJson(): void
	{
		$key = $this->svc->randomKey();
		$this->assertNull($this->svc->open('{bad json', $key));
		$this->assertNull($this->svc->open('{"v":2,"alg":"xs20p1305"}', $key));
		$this->assertNull($this->svc->open(json_encode(['v' => 1, 'alg' => 'xs20p1305', 'n' => 'short', 'c' => 'x']), $key));
	}

	public function testChunkedRoundtripMultiAndSingleChunk(): void
	{
		$key = $this->svc->randomKey();
		$small = 'tiny';
		$env = $this->svc->sealChunked($small, $key);
		$this->assertSame($small, $this->svc->openChunked($env, $key));

		$large = random_bytes(200_000); // forces 4 chunks at 64KiB
		$env = $this->svc->sealChunked($large, $key, 65536);
		$this->assertTrue($this->svc->isValidEnvelope($env, EnvelopeService::ALG_CHUNKED));
		$decoded = json_decode($env, true);
		$this->assertGreaterThan(1, count($decoded['chunks']));
		$this->assertSame($large, $this->svc->openChunked($env, $key));
	}

	public function testChunkedRejectsSmallChunkSize(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->sealChunked('x', $this->svc->randomKey(), 1024);
	}

	public function testChunkedTamperInSecondChunkDetected(): void
	{
		$key = $this->svc->randomKey();
		$data = str_repeat('AB', 100_000);
		$env = json_decode($this->svc->sealChunked($data, $key, 65536), true);
		$ct = base64_decode($env['chunks'][1], true);
		$ct[strlen($ct) - 1] = chr(ord($ct[strlen($ct) - 1]) ^ 1);
		$env['chunks'][1] = base64_encode($ct);
		$this->assertNull($this->svc->openChunked(json_encode($env), $key));
	}

	public function testChunkedEmptyChunkListRejected(): void
	{
		$env = json_encode(['v' => 1, 'alg' => 'xs20p1305-chunk', 'n' => base64_encode(random_bytes(24)), 'chunks' => []]);
		$this->assertNull($this->svc->openChunked($env, $this->svc->randomKey()));
		$this->assertFalse($this->svc->isValidEnvelope($env));
	}

	public function testSealedBoxToPubkey(): void
	{
		$kp = sodium_crypto_box_keypair();
		$pk = base64_encode(sodium_crypto_box_publickey($kp));
		$env = $this->svc->sealToPubkey('wrapped-key-material', $pk);
		$this->assertTrue($this->svc->isValidEnvelope($env, EnvelopeService::ALG_SEALEDBOX));
		$decoded = json_decode($env, true);
		$plain = sodium_crypto_box_seal_open(base64_decode($decoded['c'], true), $kp);
		$this->assertSame('wrapped-key-material', $plain);
	}

	public function testSealedBoxRejectsBadPubkey(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->sealToPubkey('x', base64_encode('short'));
	}

	public function testOpenRejectsWrongAlgWithOtherwiseValidShape(): void
	{
		// A wrong-alg envelope carrying a VALID nonce+ciphertext must still be
		// rejected — otherwise an attacker relabels a sealed box as secretbox
		// and the decrypt path proceeds where it must not.
		$key = $this->svc->randomKey();
		$env = json_decode($this->svc->seal('relabeled', $key), true);
		$env['alg'] = 'x25519-seal';
		$this->assertNull($this->svc->open(json_encode($env), $key));
	}

	public function testIsValidEnvelopeEnforcesRequiredAlg(): void
	{
		$key = $this->svc->randomKey();
		$secretbox = $this->svc->seal('x', $key);
		$sealed = $this->svc->sealToPubkey('x', base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())));
		$this->assertTrue($this->svc->isValidEnvelope($secretbox, 'xs20p1305'));
		$this->assertFalse($this->svc->isValidEnvelope($secretbox, 'x25519-seal'));
		$this->assertTrue($this->svc->isValidEnvelope($sealed, 'x25519-seal'));
		$this->assertFalse($this->svc->isValidEnvelope($sealed, 'xs20p1305'));
	}

	public function testIsValidEnvelopeRejectsUnknownAlg(): void
	{
		$this->assertFalse($this->svc->isValidEnvelope(json_encode(['v' => 1, 'alg' => 'rot13', 'c' => 'x'])));
		$this->assertFalse($this->svc->isValidEnvelope('plain text, not json'));
		$this->assertFalse($this->svc->isValidEnvelope(json_encode(['v' => 1, 'alg' => 'xs20p1305']))); // missing n/c
	}

	public function testPubkeyFingerprint(): void
	{
		$pk = base64_encode(random_bytes(32));
		$fp = $this->svc->pubkeyFingerprint($pk);
		$this->assertSame(64, strlen($fp));
		$this->assertSame($fp, $this->svc->pubkeyFingerprint($pk));
		$this->assertSame('', $this->svc->pubkeyFingerprint('!!!notb64'));
	}
}
