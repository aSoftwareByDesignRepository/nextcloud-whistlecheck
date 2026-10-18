<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AttachmentService;
use OCA\WhistleCheck\Service\ConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentServiceTest extends TestCase
{
	private ConfigService&MockObject $config;
	private EnvelopeService $envelope;
	private AttachmentService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(ConfigService::class);
		$this->envelope = new EnvelopeService();
		$this->svc = new AttachmentService($this->envelope, $this->config);
	}

	private function configOn(int $maxMb = 2): void
	{
		$this->config->method('getBool')->willReturnCallback(
			fn (string $k) => $k === 'attachments_enabled'
		);
		$this->config->method('getInt')->willReturnCallback(
			fn (string $k) => $k === 'max_attachment_mb' ? $maxMb : 10
		);
	}

	// ── validateCipher ───────────────────────────────────────────────────

	public function testValidateCipherRejectsWhenDisabled(): void
	{
		$this->config->method('getBool')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->svc->validateCipher('{}', 'image/png');
	}

	public function testValidateCipherRejectsUnlistedMime(): void
	{
		$this->configOn();
		$env = $this->envelope->seal('x', $this->envelope->randomKey());
		try {
			$this->svc->validateCipher($env, 'application/x-msdownload');
			$this->fail('expected rejection');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('attachments', $e->getFields());
		}
	}

	public function testValidateCipherRejectsNonEnvelope(): void
	{
		$this->configOn();
		$this->expectException(ValidationException::class);
		$this->svc->validateCipher('{"v":2,"alg":"bogus"}', 'image/png');
	}

	public function testValidateCipherRejectsOversized(): void
	{
		$this->configOn(1); // 1 MiB plaintext bound → 2 MiB ciphertext bound
		$this->expectException(ValidationException::class);
		$this->svc->validateCipher(str_repeat('a', 3 * 1048576), 'text/plain');
	}

	public function testValidateCipherRejectsEmpty(): void
	{
		$this->configOn();
		$this->expectException(ValidationException::class);
		$this->svc->validateCipher('', 'text/plain');
	}

	public function testValidateCipherAcceptsSecretboxAndChunked(): void
	{
		$this->configOn();
		$key = $this->envelope->randomKey();
		$boxed = $this->envelope->seal('hello', $key);
		$this->assertSame(strlen($boxed), $this->svc->validateCipher($boxed, 'text/plain'));
		$chunked = $this->envelope->sealChunked('hello', $key, 65536);
		$this->assertSame(strlen($chunked), $this->svc->validateCipher($chunked, 'image/png'));
	}

	public function testValidateCipherRejectsWrapEnvelopeAsAttachment(): void
	{
		$this->configOn();
		$keypair = sodium_crypto_box_keypair();
		$wrap = $this->envelope->sealToPubkey(
			$this->envelope->randomKey(),
			base64_encode(sodium_crypto_box_publickey($keypair))
		);
		$this->expectException(ValidationException::class);
		$this->svc->validateCipher($wrap, 'image/png');
	}

	// ── sniff ────────────────────────────────────────────────────────────

	public function testSniffSignatures(): void
	{
		$this->assertTrue($this->svc->sniff("\x89PNG\r\n\x1a\nrest", 'image/png'));
		$this->assertTrue($this->svc->sniff("\xFF\xD8\xFF\xE0rest", 'image/jpeg'));
		$this->assertTrue($this->svc->sniff("RIFF\x00\x00\x00\x00WEBPrest", 'image/webp'));
		$this->assertFalse($this->svc->sniff("RIFF\x00\x00\x00\x00WAVErest", 'image/webp'), 'RIFF container without WEBP tag must fail');
		$this->assertTrue($this->svc->sniff('GIF89arest', 'image/gif'));
		$this->assertTrue($this->svc->sniff('GIF87arest', 'image/gif'));
		$this->assertTrue($this->svc->sniff('%PDF-1.7 rest', 'application/pdf'));
		$this->assertFalse($this->svc->sniff('PK\x03\x04zip', 'image/png'));
		$this->assertFalse($this->svc->sniff('%PDF-1.7 rest', 'image/jpeg'));
	}

	public function testSniffTextRejectsBinaryInDisguise(): void
	{
		$this->assertTrue($this->svc->sniff("plain text\nwith newlines", 'text/plain'));
		$this->assertFalse($this->svc->sniff("text\0with nul", 'text/plain'));
	}

	// ── sanitizePlain ────────────────────────────────────────────────────

	public function testSanitizePlainRejectsSniffMismatch(): void
	{
		$this->configOn();
		try {
			$this->svc->sanitizePlain('MZ\x90\x00exe bytes', 'image/png');
			$this->fail('expected rejection');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('attachments', $e->getFields());
		}
	}

	public function testSanitizePlainEnforcesPlaintextBound(): void
	{
		$this->configOn(1);
		$this->expectException(ValidationException::class);
		$this->svc->sanitizePlain(str_repeat('a', 1048576 + 1), 'text/plain');
	}

	public function testSanitizePlainPassesTextThrough(): void
	{
		$this->configOn();
		$data = "line one\nline two";
		$this->assertSame($data, $this->svc->sanitizePlain($data, 'text/plain'));
	}

	public function testStripMetadataReencodesImageWhenGdPresent(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('gd not loaded');
		}
		// Build a real PNG, append a fake EXIF-ish tail after IEND — GD
		// re-encode must produce a parseable PNG without trailing junk.
		$img = imagecreatetruecolor(4, 4);
		ob_start();
		imagepng($img);
		$png = (string)ob_get_clean();
		$dirty = $png . 'EXIF-GARBAGE-TAIL';
		$out = $this->svc->stripMetadata($dirty, 'image/png');
		$this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $out);
		$this->assertStringEndsNotWith('EXIF-GARBAGE-TAIL', $out, 'GD re-encode must drop trailing metadata');
		$this->assertNotFalse(imagecreatefromstring($out), 're-encoded output must remain a valid image');
	}

	public function testStripMetadataPassesNonImageThrough(): void
	{
		$data = '%PDF-1.7 bytes with metadata';
		$this->assertSame($data, $this->svc->stripMetadata($data, 'application/pdf'));
	}
}
