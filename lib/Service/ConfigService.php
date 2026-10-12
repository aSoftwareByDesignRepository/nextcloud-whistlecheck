<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

use OCA\WhistleCheck\Db\ConfigEntry;
use OCA\WhistleCheck\Db\ConfigEntryMapper;
use OCA\WhistleCheck\Exception\ConflictException;
use OCA\WhistleCheck\Exception\ValidationException;

/**
 * Org channel config — auditable key/value with CAS (spec US-008).
 *
 * Every write requires the current revision; missing/stale token → 409
 * (fail-closed, api-security §3). Value shapes are validated per key so a
 * tampered UI cannot persist nonsense.
 */
class ConfigService
{
	public const KEYS = [
		'org_name', 'retention_months', 'reminder_days',
		'escalation_uid', 'attachments_enabled', 'max_attachment_mb',
		'welcome_text', 'nojs_enabled', 'require_pow_after',
		'two_person_close',
	];

	private const INT_KEYS = [
		'retention_months' => [1, 120],
		'reminder_days' => [0, 90],
		'max_attachment_mb' => [1, 50],
		'require_pow_after' => [1, 100],
	];

	private const BOOL_KEYS = ['attachments_enabled', 'nojs_enabled', 'two_person_close'];

	public const DEFAULTS = [
		'org_name' => '',
		'retention_months' => '36',
		'reminder_days' => '14',
		'escalation_uid' => '',
		'attachments_enabled' => '1',
		'max_attachment_mb' => '10',
		'welcome_text' => '',
		'nojs_enabled' => '1',
		'require_pow_after' => '2',
		'two_person_close' => '1',
	];

	public function __construct(
		private readonly ConfigEntryMapper $mapper,
		private readonly \OCP\IUserManager $userManager,
		private readonly OfficerKeyService $officers,
	) {
	}

	public function get(string $key): string
	{
		$row = $this->mapper->findByKey($key);
		return $row?->getCvalue() ?? (self::DEFAULTS[$key] ?? '');
	}

	public function getInt(string $key): int
	{
		$v = $this->get($key);
		return preg_match('/^-?\d+$/', $v) === 1 ? (int)$v : (int)(self::DEFAULTS[$key] ?? 0);
	}

	public function getBool(string $key): bool
	{
		return $this->get($key) === '1';
	}

	/**
	 * @return array<string,string> all config values + revision map for CAS
	 */
	public function getAll(): array
	{
		$out = self::DEFAULTS;
		foreach ($this->mapper->findAll() as $row) {
			$out[$row->getCkey()] = (string)($row->getCvalue() ?? '');
		}
		return $out;
	}

	/** @return array<string,int> key → current revision (0 when never set) */
	public function revisions(): array
	{
		$out = [];
		foreach ($this->mapper->findAll() as $row) {
			$out[$row->getCkey()] = (int)$row->getRevision();
		}
		return $out;
	}

	/**
	 * CAS write. `revision` must equal the row's current revision (or 0 for a
	 * never-written key); anything else → 409, full stop (api-security §3).
	 *
	 * @throws ValidationException
	 * @throws ConflictException
	 */
	public function set(string $key, string $value, int $revision, ?string $actorUid): void
	{
		if (!in_array($key, self::KEYS, true)) {
			throw new ValidationException('invalid_key', 'Unknown config key', [$key => 'unknown key']);
		}
		$clean = $this->validate($key, $value);

		$row = $this->mapper->findByKey($key);
		if ($row === null) {
			if ($revision !== 0) {
				throw new ConflictException();
			}
			$row = new ConfigEntry();
			$row->setCkey($key);
			$row->setCvalue($clean);
			$row->setUpdatedBy($actorUid);
			$row->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
			$row->setRevision(1);
			$this->mapper->insert($row);
			return;
		}
		if ($row->getRevision() !== $revision) {
			throw new ConflictException();
		}
		$row->setCvalue($clean);
		$row->setUpdatedBy($actorUid);
		$row->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
		$row->setRevision($revision + 1);
		$this->mapper->update($row);
	}

	/**
	 * @throws ValidationException
	 */
	public function validate(string $key, string $value): string
	{
		if (isset(self::INT_KEYS[$key])) {
			if (preg_match('/^\d+$/', $value) !== 1) {
				throw new ValidationException('invalid_value', 'Expected a whole number', [$key => 'must be a whole number']);
			}
			$v = (int)$value;
			[$min, $max] = self::INT_KEYS[$key];
			if ($v < $min || $v > $max) {
				throw new ValidationException('invalid_value', 'Out of range', [$key => "must be between $min and $max"]);
			}
			return (string)$v;
		}
		if (in_array($key, self::BOOL_KEYS, true)) {
			// Explicit parse — form "false"/"0" is a string, not false (§4).
			if (!in_array($value, ['0', '1', 'true', 'false'], true)) {
				throw new ValidationException('invalid_value', 'Expected yes/no', [$key => 'invalid value']);
			}
			return in_array($value, ['1', 'true'], true) ? '1' : '0';
		}
		if ($key === 'org_name' || $key === 'welcome_text') {
			if (mb_strlen($value) > 2000) {
				throw new ValidationException('invalid_value', 'Too long', [$key => 'too long']);
			}
			return $value;
		}
		if ($key === 'escalation_uid') {
			if ($value === '') {
				return $value; // empty = unset escalation target
			}
			// Must be a real user AND a current officer — a mistyped uid would
			// silently disable statutory deadline escalation.
			if (!preg_match('/^[a-zA-Z0-9_\-.@]+$/', $value)
				|| $this->userManager->get($value) === null
				|| !$this->officers->isOfficer($value)) {
				throw new ValidationException('invalid_value', 'Invalid user', [$key => 'must be an active case officer']);
			}
			return $value;
		}
		return $value;
	}
}
