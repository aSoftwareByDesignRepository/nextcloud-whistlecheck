<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Controller;

use OCA\WhistleCheck\Exception\ConflictException;
use OCA\WhistleCheck\Exception\DomainException;
use OCA\WhistleCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Uniform JSON contract for every WhistleCheck endpoint:
 *
 *   success: {"success": true, "data": {...}}
 *   failure: {"success": false, "error": "CODE", "message": "…", "fields": {…}}
 *
 * NOT_FOUND is canonical: every "not found / not yours" collapse happens HERE,
 * at the serialization boundary, so per-entity codes can never leak an
 * existence oracle (api-security-patterns §1).
 */
trait ApiControllerTrait
{
	/** @param array<string,mixed> $data */
	private function ok(array $data = [], int $status = Http::STATUS_OK): JSONResponse
	{
		return new JSONResponse(['success' => true, 'data' => $data], $status);
	}

	private function fail(string $code, string $message, int $status = 400, array $fields = [], ?int $retryAfter = null): JSONResponse
	{
		$res = new JSONResponse([
			'success' => false,
			'error' => strtoupper($code),
			'message' => $message,
			'fields' => $fields,
		], $status);
		$res->addHeader('Cache-Control', 'no-store');
		if ($retryAfter !== null) {
			$res->addHeader('Retry-After', (string)$retryAfter);
		}
		return $res;
	}

	/**
	 * Canonical 404 — byte-identical regardless of "doesn't exist" vs "not yours".
	 */
	private function notFound(): JSONResponse
	{
		return $this->fail('NOT_FOUND', 'Not found.', 404);
	}

	private function fromThrowable(\Throwable $e): JSONResponse
	{
		if ($e instanceof ValidationException) {
			return $this->fail($e->getErrorCode(), $e->getMessage(), 400, $e->getFields());
		}
		if ($e instanceof ConflictException) {
			return $this->fail('CONFLICT', $e->getMessage(), 409);
		}
		if ($e instanceof DomainException) {
			// Domain not-found collapses to the canonical payload.
			if ($e->httpStatus === 404) {
				return $this->notFound();
			}
			return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, [], $e->retryAfterSeconds);
		}
		// Class + location only: exception messages can embed bound input
		// values (PDO/driver errors) and must not reach the error log.
		error_log('WCHK-INTERNAL ' . get_class($e) . ' @ ' . $e->getFile() . ':' . $e->getLine());
		return $this->fail('INTERNAL', 'Internal error', 500);
	}

	/**
	 * Parse a request field as strict boolean — "false" is a STRING in
	 * form-encoded payloads and must never pass a truthiness check (§4).
	 */
	private function boolParam(mixed $v): bool
	{
		return $v === true || $v === '1' || $v === 'true' || $v === 1;
	}

	/**
	 * Strict integer param — digits only, no float/exponent truncation (§7).
	 */
	private function intParam(mixed $v): ?int
	{
		if (is_int($v)) {
			return $v;
		}
		if (is_string($v) && preg_match('/^\d+$/', $v) === 1) {
			return (int)$v;
		}
		return null;
	}
}
