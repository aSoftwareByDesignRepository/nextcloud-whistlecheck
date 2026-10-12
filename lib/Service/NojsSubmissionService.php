<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Crypto\CaseCodeService;
use OCA\WhistleCheck\Crypto\EnvelopeService;
use OCA\WhistleCheck\Exception\ValidationException;

/**
 * No-JS submission path (spec US-010 / MH-10).
 *
 * Honest degradation, never hidden: the server generates the case code and
 * content key, derives all artifacts server-side, encrypts, wraps, stores —
 * and marks the case created_e2e=0. The UI states plainly that end-to-end
 * requires JavaScript and that this path encrypts in transit + at rest only.
 * Plaintext lives only in request memory; nothing is logged or persisted.
 */
class NojsSubmissionService
{
	public function __construct(
		private readonly CaseCodeService $codes,
		private readonly EnvelopeService $envelope,
		private readonly CaseService $cases,
		private readonly AttachmentService $attachments,
		private readonly OfficerKeyService $officerKeys,
		private readonly SanitizeService $sanitize,
	) {
	}

	/**
	 * Server-side encrypt + submit.
	 *
	 * @param list<array{data:string,mime:string,name:string}> $files plaintext attachments
	 * @return array{case_id:int,code:string}
	 * @throws ValidationException
	 */
	public function submit(string $title, string $body, string $contact, ?int $categoryId, array $files, string $ikey): array
	{
		$fields = [];
		$body = $this->sanitize->plainText($body);
		if ($body === '') {
			$fields['body'] = 'required';
		}
		$title = $this->sanitize->plainText($title, SanitizeService::MAX_TITLE);
		$contact = $this->sanitize->plainText($contact, SanitizeService::MAX_CONTACT);
		if ($fields !== []) {
			throw new ValidationException('invalid', 'Invalid submission', $fields);
		}

		$gen = $this->codes->generate();
		$codeBytes = $gen['bytes'];
		$codeSalt = $this->codes->newSalt();
		$keySalt = $this->codes->newSalt();
		$caseKey = $this->envelope->randomKey();
		$kReporter = $this->codes->reporterKey($codeBytes, $keySalt);

		try {
			$wraps = [];
			foreach ($this->officerKeys->activePubkeys() as $o) {
				$wraps[$o['uid']] = $this->envelope->sealToPubkey($caseKey, $o['pubkey']);
			}
			if ($wraps === []) {
				throw new ValidationException('no_officers', 'Channel is not ready', ['body' => 'channel not ready']);
			}

			$attachmentPayloads = [];
			foreach ($files as $f) {
				$clean = $this->attachments->sanitizePlain($f['data'], $f['mime']);
				$attachmentPayloads[] = [
					'cipher' => $this->envelope->sealChunked($clean, $caseKey),
					'mime' => $f['mime'],
					'name_cipher' => $this->envelope->seal($f['name'], $caseKey),
				];
			}

			// Receipt text is server-encrypted to the case key — the reporter
			// decrypts it like any officer message.
			$receipt = $this->envelope->seal('wchk:receipt', $caseKey);

			$result = $this->cases->submit([
				'ikey' => $ikey,
				'hint' => $this->codes->lookupHint($codeBytes),
				'verifier' => $this->codes->verifier($codeBytes, $codeSalt),
				'code_salt' => $codeSalt,
				'key_salt' => $keySalt,
				'reporter_wrap' => $this->envelope->seal($caseKey, $kReporter),
				'title_cipher' => $title !== '' ? $this->envelope->seal($title, $caseKey) : '',
				'body' => $this->envelope->seal($body, $caseKey),
				'contact_cipher' => $contact !== '' ? $this->envelope->seal($contact, $caseKey) : '',
				'category_id' => $categoryId,
				'wraps' => $wraps,
				'attachments' => $attachmentPayloads,
				'receipt_cipher' => $receipt,
			], false);
		} finally {
			// Best-effort scrub of key material from request memory.
			sodium_memzero($caseKey);
			sodium_memzero($kReporter);
			sodium_memzero($codeBytes);
		}
		return ['case_id' => $result['case_id'], 'code' => $gen['code']];
	}
}
