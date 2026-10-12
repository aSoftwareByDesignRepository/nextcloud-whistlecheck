<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One message in a case thread. `cipher` is a JSON envelope
 * {v, alg, nonce, ct} — the server never sees plaintext.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $v)
 * @method string getDirection()
 * @method void setDirection(string $v)
 * @method string getCipher()
 * @method void setCipher(string $v)
 * @method string|null getAuthorUid()
 * @method void setAuthorUid(?string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Wmessage extends Entity
{
	public const DIR_REPORTER = 'reporter';
	public const DIR_OFFICER = 'officer';
	public const DIR_SYSTEM = 'system';

	protected $caseId;
	protected $direction;
	protected $cipher;
	protected $authorUid;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('caseId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
