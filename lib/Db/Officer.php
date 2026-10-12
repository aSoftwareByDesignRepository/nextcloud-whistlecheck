<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Case officer key registration. Only the PUBLIC key lives here — the private
 * key is Argon2id(passphrase)-wrapped in the officer's browser IndexedDB and
 * never touches the server (spec US-007).
 *
 * @method string getUid()
 * @method void setUid(string $v)
 * @method string getPubkey()
 * @method void setPubkey(string $v)
 * @method string getFingerprint()
 * @method void setFingerprint(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method int getKeyVersion()
 * @method void setKeyVersion(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(?\DateTime $v)
 * @method string|null getRevokedBy()
 * @method void setRevokedBy(?string $v)
 */
class Officer extends Entity
{
	public const STATUS_ACTIVE = 'active';
	public const STATUS_REVOKED = 'revoked';

	protected $uid;
	protected $pubkey;
	protected $fingerprint;
	protected $status;
	protected $keyVersion;
	protected $createdAt;
	protected $revokedAt;
	protected $revokedBy;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('keyVersion', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
	}
}
