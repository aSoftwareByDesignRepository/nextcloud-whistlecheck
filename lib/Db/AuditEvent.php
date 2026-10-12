<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Append-only, hash-chained audit entry. Payload is content-free by contract
 * (spec NN-04): fixed column set + whitelisted scalar fields only.
 *
 * @method int getSeq()
 * @method void setSeq(int $v)
 * @method string getPrevHash()
 * @method void setPrevHash(string $v)
 * @method string getHash()
 * @method void setHash(string $v)
 * @method string getEventType()
 * @method void setEventType(string $v)
 * @method string|null getActorUid()
 * @method void setActorUid(?string $v)
 * @method int|null getCaseId()
 * @method void setCaseId(?int $v)
 * @method string|null getPayloadJson()
 * @method void setPayloadJson(?string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class AuditEvent extends Entity
{
	protected $seq;
	protected $prevHash;
	protected $hash;
	protected $eventType;
	protected $actorUid;
	protected $caseId;
	protected $payloadJson;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('seq', 'integer');
		$this->addType('caseId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
