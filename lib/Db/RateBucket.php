<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Rotating-salt rate bucket: hash(ip, daily_salt). The salt is destroyed daily,
 * so no persisted identifier ever survives 24h (spec C1).
 *
 * @method string getBucketHash()
 * @method void setBucketHash(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method int getHits()
 * @method void setHits(int $v)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $v)
 */
class RateBucket extends Entity
{
	protected $bucketHash;
	protected $kind;
	protected $hits;
	protected $expiresAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('hits', 'integer');
		$this->addType('expiresAt', 'datetime');
	}
}
