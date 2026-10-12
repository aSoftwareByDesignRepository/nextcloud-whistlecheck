<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Attachment ciphertext (chunked secretbox JSON envelope). `mimeClaimed` is
 * stored for the client's decrypt-and-download flow only — it is never trusted
 * for rendering. `nameCipher` keeps the reporter's filename encrypted (metadata
 * minimisation).
 *
 * @method int getCaseId()
 * @method void setCaseId(int $v)
 * @method int|null getMessageId()
 * @method void setMessageId(?int $v)
 * @method string getCipher()
 * @method void setCipher(string $v)
 * @method string|null getNameCipher()
 * @method void setNameCipher(?string $v)
 * @method int getSize()
 * @method void setSize(int $v)
 * @method string getMimeClaimed()
 * @method void setMimeClaimed(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Wattachment extends Entity
{
	protected $caseId;
	protected $messageId;
	protected $cipher;
	protected $nameCipher;
	protected $size;
	protected $mimeClaimed;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('caseId', 'integer');
		$this->addType('messageId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
