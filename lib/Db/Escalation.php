<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One-way escalation reference: officer exported a self-authored summary into
 * a sibling app (FC-WCHK-EXPORT). Stores only the destination reference —
 * never the ticket's content and nothing the sibling could use to point back.
 * The row dies with the case (retention purge) — the hash-chained audit event
 * 'case.escalated' retains the ticket number as the durable proof.
 *
 * @method int getCaseId()
 * @method void setCaseId(int $v)
 * @method string getOfficerUid()
 * @method void setOfficerUid(string $v)
 * @method string getTicketApp()
 * @method void setTicketApp(string $v)
 * @method int getTicketId()
 * @method void setTicketId(int $v)
 * @method string getTicketNumber()
 * @method void setTicketNumber(string $v)
 * @method string|null getIdemKey()
 * @method void setIdemKey(?string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Escalation extends Entity
{
	protected $caseId;
	protected $officerUid;
	protected $ticketApp;
	protected $ticketId;
	protected $ticketNumber;
	protected $idemKey;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('caseId', 'integer');
		$this->addType('ticketId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
