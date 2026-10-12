<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Content key wrapped (crypto_box_seal) for one officer on one case.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $v)
 * @method string getOfficerUid()
 * @method void setOfficerUid(string $v)
 * @method string getWrappedKey()
 * @method void setWrappedKey(string $v)
 * @method string|null getGrantedBy()
 * @method void setGrantedBy(?string $v)
 * @method \DateTime getGrantedAt()
 * @method void setGrantedAt(\DateTime $v)
 */
class CaseKeyWrap extends Entity
{
	protected $caseId;
	protected $officerUid;
	protected $wrappedKey;
	protected $grantedBy;
	protected $grantedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('caseId', 'integer');
		$this->addType('grantedAt', 'datetime');
	}
}
