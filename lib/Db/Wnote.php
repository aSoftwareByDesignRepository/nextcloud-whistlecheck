<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Internal officer note — encrypted to officer keys only (spec US-004 AC-5).
 * Invisible to the reporter by construction: reporters never receive note rows.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $v)
 * @method string getCipher()
 * @method void setCipher(string $v)
 * @method string getAuthorUid()
 * @method void setAuthorUid(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Wnote extends Entity
{
	protected $caseId;
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
