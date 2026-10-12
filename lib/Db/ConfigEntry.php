<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Org channel configuration (auditable, content-free). Every write bumps
 * `revision` — the settings API requires it as CAS token (fail-closed 409).
 *
 * @method string getCkey()
 * @method void setCkey(string $v)
 * @method string|null getCvalue()
 * @method void setCvalue(?string $v)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $v)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?\DateTime $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 */
class ConfigEntry extends Entity
{
	protected $ckey;
	protected $cvalue;
	protected $updatedBy;
	protected $updatedAt;
	protected $revision;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('updatedAt', 'datetime');
	}
}
