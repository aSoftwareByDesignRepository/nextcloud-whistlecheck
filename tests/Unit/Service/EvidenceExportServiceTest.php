<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Db\AuditEventMapper;
use OCA\WhistleCheck\Db\Wcase;
use OCA\WhistleCheck\Service\AuditService;
use OCA\WhistleCheck\Service\EvidenceExportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EvidenceExportServiceTest extends TestCase
{
	private AuditEventMapper&MockObject $auditMapper;
	private AuditService&MockObject $audit;
	private EvidenceExportService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->auditMapper = $this->createMock(AuditEventMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->svc = new EvidenceExportService($this->auditMapper, $this->audit);
	}

	private function case(): Wcase
	{
		$c = new Wcase();
		$c->setId(42);
		$c->setStatus(Wcase::STATUS_CLOSED);
		$c->setReceivedAt(new \DateTime('2026-01-05 10:00:00', new \DateTimeZone('UTC')));
		$c->setFeedbackDue(new \DateTime('2026-04-05', new \DateTimeZone('UTC')));
		$c->setClosedAt(new \DateTime('2026-02-01 12:00:00', new \DateTimeZone('UTC')));
		return $c;
	}

	public function testManifestCarriesMetadataAndDigestsNeverContent(): void
	{
		$this->auditMapper->method('findForCase')->willReturn([new \stdClass(), new \stdClass()]);
		$this->audit->method('verifyChain')->willReturn(-1);

		$digests = [
			['kind' => 'message', 'id' => 7, 'sha256' => str_repeat('ab', 32)],
			['kind' => 'attachment', 'id' => 9, 'sha256' => str_repeat('cd', 32)],
		];
		$out = $this->svc->build($this->case(), $digests, 'officer1');
		$m = $out['manifest'];

		$this->assertSame('whistlecheck-evidence-v1', $m['format']);
		$this->assertSame(42, $m['case_id']);
		$this->assertSame('closed', $m['status']);
		$this->assertTrue($m['audit_chain_intact']);
		$this->assertSame(2, $m['audit_entries']);
		$this->assertSame($digests, $m['blob_digests']);
		$this->assertSame('officer1', $m['exported_by']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $m['manifest_sha256']);
		// The manifest hash covers everything else in the manifest.
		$recomputed = $m;
		unset($recomputed['manifest_sha256']);
		$this->assertSame(hash('sha256', json_encode($recomputed)), $m['manifest_sha256']);
	}

	public function testManifestFlagsBrokenChain(): void
	{
		$this->auditMapper->method('findForCase')->willReturn([]);
		$this->audit->method('verifyChain')->willReturn(3);
		$out = $this->svc->build($this->case(), [], 'o');
		$this->assertFalse($out['manifest']['audit_chain_intact']);
		$this->assertStringContainsString('BROKEN', $out['pdf']);
	}

	public function testPdfIsStructurallyValid(): void
	{
		$this->auditMapper->method('findForCase')->willReturn([]);
		$this->audit->method('verifyChain')->willReturn(-1);
		$out = $this->svc->build($this->case(), [
			['kind' => 'message', 'id' => 1, 'sha256' => str_repeat('ef', 32)],
		], 'officer1');
		$pdf = $out['pdf'];

		$this->assertStringStartsWith('%PDF-1.4', $pdf);
		$this->assertStringEndsWith("%%EOF\n", $pdf);
		$this->assertStringContainsString('xref', $pdf);
		$this->assertStringContainsString('startxref', $pdf);
		$this->assertStringContainsString('Case id:        42', $pdf);
		$this->assertStringContainsString(str_repeat('ef', 32), $pdf);
		$this->assertStringContainsString($out['manifest']['manifest_sha256'], $pdf);
		$this->assertStringContainsString('officer1', $pdf);
	}

	public function testPdfPaginatesLongDigestLists(): void
	{
		$this->auditMapper->method('findForCase')->willReturn([]);
		$this->audit->method('verifyChain')->willReturn(-1);
		$digests = [];
		for ($i = 0; $i < 60; $i++) {
			$digests[] = ['kind' => 'blob', 'id' => $i, 'sha256' => str_repeat('aa', 32)];
		}
		$out = $this->svc->build($this->case(), $digests, 'o');
		// >46 lines → more than one /Page object
		$this->assertGreaterThan(1, substr_count($out['pdf'], '/Type /Page '));
	}

	public function testPdfEscapesMetacharactersInFields(): void
	{
		$this->auditMapper->method('findForCase')->willReturn([]);
		$this->audit->method('verifyChain')->willReturn(-1);
		$out = $this->svc->build($this->case(), [
			['kind' => 'evil) (inject', 'id' => 1, 'sha256' => 'x'],
		], 'o)(ffi');
		// Literal parens in values must be escaped so the content stream parses.
		$this->assertStringContainsString('o\\)\\(ffi', $out['pdf']);
	}
}
