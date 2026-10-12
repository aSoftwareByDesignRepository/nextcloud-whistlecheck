<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\AuditEventMapper;
use OCA\WhistleCheck\Db\Wcase;

/**
 * Sealed evidence export (spec US-015 / NH-02).
 *
 * The server CANNOT decrypt case content — a "PDF of the report" is
 * impossible by design. What it can seal is an integrity record: case
 * metadata, ciphertext digests (sha256 per stored blob), and the audit-chain
 * segment with its head hash. The officer-side client optionally embeds its
 * decrypted rendering later; this export is the tamper-evident spine.
 *
 * Output: a minimal, valid PDF (text-only, generated without dependencies)
 * containing the manifest + a hex digest of every ciphertext.
 */
class EvidenceExportService
{
	public function __construct(
		private readonly AuditEventMapper $auditMapper,
		private readonly AuditService $audit,
	) {
	}

	/**
	 * Build the export manifest.
	 *
	 * @param list<array<string,mixed>> $digests per-blob ciphertext sha256
	 * @return array{manifest:array<string,mixed>, pdf:string}
	 */
	public function build(Wcase $case, array $digests, string $actorUid): array
	{
		$caseAudit = $this->auditMapper->findForCase($case->getId(), 10000);
		$chainOk = $this->audit->verifyChain() === -1;

		$manifest = [
			'format' => 'whistlecheck-evidence-v1',
			'case_id' => $case->getId(),
			'status' => $case->getStatus(),
			'received_at' => $case->getReceivedAt()->format(\DateTime::ATOM),
			'closed_at' => $case->getClosedAt()?->format(\DateTime::ATOM),
			'feedback_due' => $case->getFeedbackDue()->format('Y-m-d'),
			'exported_at' => (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM),
			'exported_by' => $actorUid,
			'audit_chain_intact' => $chainOk,
			'audit_entries' => count($caseAudit),
			'blob_digests' => $digests,
		];
		$manifest['manifest_sha256'] = hash('sha256', (string)json_encode($manifest, JSON_THROW_ON_ERROR));

		return ['manifest' => $manifest, 'pdf' => $this->renderPdf($manifest)];
	}

	/**
	 * Minimal single-purpose PDF writer: Helvetica text lines, one page per
	 * ~46 lines. Enough for an integrity record — no layout risk, no deps.
	 */
	private function renderPdf(array $manifest): string
	{
		$lines = [
			'WhistleCheck — sealed evidence record',
			'',
			'Case id:        ' . $manifest['case_id'],
			'Status:         ' . $manifest['status'],
			'Received at:    ' . $manifest['received_at'],
			'Closed at:      ' . ($manifest['closed_at'] ?? '-'),
			'Feedback due:   ' . $manifest['feedback_due'],
			'Exported at:    ' . $manifest['exported_at'],
			'Exported by:    ' . $manifest['exported_by'],
			'Audit chain:    ' . ($manifest['audit_chain_intact'] ? 'intact' : 'BROKEN'),
			'Audit entries:  ' . $manifest['audit_entries'],
			'',
			'Ciphertext digests (sha256):',
		];
		foreach ($manifest['blob_digests'] as $d) {
			$lines[] = '  ' . ($d['kind'] ?? 'blob') . ' #' . ($d['id'] ?? '?') . '  ' . ($d['sha256'] ?? '');
		}
		$lines[] = '';
		$lines[] = 'Manifest sha256: ' . $manifest['manifest_sha256'];

		return $this->pdf($lines);
	}

	/**
	 * @param list<string> $lines
	 */
	private function pdf(array $lines): string
	{
		$perPage = 46;
		$pages = array_chunk($lines, $perPage);
		$objects = [];
		$kids = [];

		// 1 catalog, 2 pages tree, 99 font; pages start at object 3 (page, content pairs)
		foreach ($pages as $i => $pageLines) {
			$content = "BT /F1 10 Tf 50 790 Td 14 TL\n";
			foreach ($pageLines as $line) {
				$content .= '(' . $this->pdfEscape($line) . ") Tj T*\n";
			}
			$content .= 'ET';
			$pageNum = 3 + $i * 2;
			$contentNum = $pageNum + 1;
			$objects[$pageNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
				. "/Resources << /Font << /F1 99 0 R >> >> /Contents $contentNum 0 R >>";
			$objects[$contentNum] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
			$kids[] = "$pageNum 0 R";
		}
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
		$objects[99] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		$pdf = "%PDF-1.4\n";
		$offsets = [];
		ksort($objects);
		foreach ($objects as $num => $body) {
			$offsets[$num] = strlen($pdf);
			$pdf .= "$num 0 obj\n$body\nendobj\n";
		}
		$xref = strlen($pdf);
		$max = max(array_keys($objects));
		$pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
		for ($i = 1; $i <= $max; $i++) {
			$pdf .= isset($offsets[$i]) ? sprintf('%010d 00000 n ', $offsets[$i]) . "\n" : "0000000000 65535 f \n";
		}
		$pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
		return $pdf;
	}

	private function pdfEscape(string $s): string
	{
		$s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
	}
}
