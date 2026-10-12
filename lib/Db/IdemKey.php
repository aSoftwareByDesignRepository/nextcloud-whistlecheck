<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Client-generated idempotency key (UUIDv4) → created case. Guarantees a retry
 * or double-click never mints a second case (spec US-001 AC-5).
 *
 * @method string getIkey()
 * @method void setIkey(string $v)
 * @method int getCaseId()
 * @method void setCaseId(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class IdemKey extends Entity
{
	protected $ikey;
	protected $caseId;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('caseId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
