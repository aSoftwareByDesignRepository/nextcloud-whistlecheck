<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Crypto\CaseCodeService;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Exception\ValidationException;
use OCA\WhistleCheck\Service\AttachmentService;
use OCA\WhistleCheck\Service\CaseService;
use OCA\WhistleCheck\Service\NojsSubmissionService;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\SanitizeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NojsSubmissionServiceTest extends TestCase
{
	private CaseService&MockObject $cases;
	private AttachmentService&MockObject $attachments;
	private OfficerKeyService&MockObject $officerKeys;
	private CaseCodeService $codes;
	private EnvelopeService $envelope;
	private NojsSubmissionService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->codes = new CaseCodeService();
		$this->envelope = new EnvelopeService();
		$this->cases = $this->createMock(CaseService::class);
		$this->attachments = $this->createMock(AttachmentService::class);
		$this->attachments->method('sanitizePlain')->willReturnArgument(0);
		$this->officerKeys = $this->createMock(OfficerKeyService::class);
		$this->svc = new NojsSubmissionService(
			$this->codes,
			$this->envelope,
			$this->cases,
			$this->attachments,
			$this->officerKeys,
			new SanitizeService(),
		);
	}

	private function officerPubkeys(): array
	{
		// Real X25519 pairs — the test opens the sealed wraps with the secret.
		$kp = sodium_crypto_box_keypair();
		$pub = base64_encode(sodium_crypto_box_publickey($kp));
		return [
			'officers' => [[
				'uid' => 'officer1',
				'pubkey' => $pub,
				'fingerprint' => $this->envelope->pubkeyFingerprint($pub),
			]],
			'secret' => sodium_crypto_box_secretkey($kp),
		];
	}

	public function testSubmitRejectsEmptyBody(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->submit('', "  \n\t ", '', null, [], 'ikey-0001');
	}

	public function testSubmitRejectsWhenNoOfficersHoldKeys(): void
	{
		$this->officerKeys->method('activePubkeys')->willReturn([]);
		$this->cases->expects($this->never())->method('submit');
		$this->expectException(ValidationException::class);
		$this->svc->submit('', 'a real report', '', null, [], 'ikey-0002');
	}

	/**
	 * Full artifact round-trip: the code the reporter receives must derive a
	 * reporter key that unwraps the case key, which decrypts the body and the
	 * receipt — and the officer sealed-box wrap must open to the same case key.
	 * Anything less only checks shapes, not that the channel actually works.
	 */
	public function testSubmitProducesDecryptableArtifacts(): void
	{
		['officers' => $officers, 'secret' => $officerSecret] = $this->officerPubkeys();
		$this->officerKeys->method('activePubkeys')->willReturn($officers);

		$submitted = null;
		$this->cases->expects($this->once())->method('submit')
			->willReturnCallback(function (array $in, bool $e2e) use (&$submitted) {
				$submitted = [$in, $e2e];
				return ['case_id' => 42, 'revision' => 0];
			});

		$result = $this->svc->submit(
			'A title', 'the actual report body', 'contact me', null,
			[['data' => 'att bytes', 'mime' => 'text/plain', 'name' => 'f.txt']],
			'ikey-0003'
		);
		[$in, $e2e] = $submitted;

		$this->assertFalse($e2e, 'no-JS submissions must be flagged non-E2E');
		$this->assertSame(42, $result['case_id']);
		$this->assertMatchesRegularExpression('/^[A-Z0-9]{5}(-[A-Z0-9]{5}){4}-[A-Z0-9]$/', $result['code']);
		$this->assertSame('ikey-0003', $in['ikey']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $in['hint']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $in['verifier']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $in['code_salt']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $in['key_salt']);

		// Every stored blob is a structurally valid envelope.
		foreach (['body', 'title_cipher', 'contact_cipher', 'reporter_wrap', 'receipt_cipher'] as $k) {
			$this->assertTrue($this->envelope->isValidEnvelope($in[$k]), "$k is not a valid envelope");
		}

		// Reporter-side round-trip: code → hint + verifier + K_reporter.
		$codeBytes = $this->codes->decodeLoose($result['code']);
		$this->assertNotNull($codeBytes);
		$this->assertSame($this->codes->lookupHint($codeBytes), $in['hint']);
		$this->assertTrue(
			$this->codes->verify($in['verifier'], strtolower(hash('sha256', $in['verifier']))),
			'verifier must satisfy the stored-hash contract'
		);
		$kReporter = $this->codes->reporterKey($codeBytes, $in['key_salt']);
		$caseKey = $this->envelope->open($in['reporter_wrap'], $kReporter);
		$this->assertNotNull($caseKey, 'reporter wrap must open with the code-derived key');
		$this->assertSame('the actual report body', $this->envelope->open($in['body'], $caseKey));
		$this->assertSame('wchk:receipt', $this->envelope->open($in['receipt_cipher'], $caseKey));
		$this->assertSame('A title', $this->envelope->open($in['title_cipher'], $caseKey));

		// Officer-side: the sealed-box wrap opens to the same case key.
		$wrap = json_decode($in['wraps']['officer1']['wrap'], true);
		$this->assertSame(EnvelopeService::ALG_SEALEDBOX, $wrap['alg']);
		$opened = sodium_crypto_box_seal_open(
			base64_decode($wrap['c']),
			sodium_crypto_box_keypair_from_secretkey_and_publickey(
				$officerSecret,
				base64_decode($officers[0]['pubkey'])
			)
		);
		$this->assertSame($caseKey, $opened, 'officer wrap must carry the same case key');
		$this->assertSame($officers[0]['fingerprint'], $in['wraps']['officer1']['fp']);

		// Attachment pipeline: sanitizePlain ran, name is sealed, blob is chunked.
		$this->assertCount(1, $in['attachments']);
		$this->assertTrue($this->envelope->isValidEnvelope($in['attachments'][0]['cipher'], EnvelopeService::ALG_CHUNKED));
		$this->assertSame('f.txt', $this->envelope->open($in['attachments'][0]['name_cipher'], $caseKey));
	}

	public function testSubmitOmitsEmptyOptionalFields(): void
	{
		['officers' => $officers] = $this->officerPubkeys();
		$this->officerKeys->method('activePubkeys')->willReturn($officers);
		$submitted = null;
		$this->cases->method('submit')->willReturnCallback(function (array $in) use (&$submitted) {
			$submitted = $in;
			return ['case_id' => 1, 'revision' => 0];
		});
		$this->svc->submit('', 'body only', '', null, [], 'ikey-0004');
		$this->assertSame('', $submitted['title_cipher']);
		$this->assertSame('', $submitted['contact_cipher']);
	}
}
