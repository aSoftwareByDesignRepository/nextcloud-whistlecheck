<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Single-use, unguessable form token for the no-JS submission path. Enforces a
 * minimum issue→submit delay instead of PoW (browsers without JS cannot compute
 * PoW — honest degradation, documented).
 *
 * @method string getToken()
 * @method void setToken(string $v)
 * @method string getBucketHash()
 * @method void setBucketHash(string $v)
 * @method \DateTime getIssuedAt()
 * @method void setIssuedAt(\DateTime $v)
 * @method \DateTime getMinSubmitAt()
 * @method void setMinSubmitAt(\DateTime $v)
 * @method int getUsed()
 * @method void setUsed(int $v)
 */
class FormToken extends Entity
{
	protected $token;
	protected $bucketHash;
	protected $issuedAt;
	protected $minSubmitAt;
	protected $used;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('used', 'integer');
		$this->addType('issuedAt', 'datetime');
		$this->addType('minSubmitAt', 'datetime');
	}
}
