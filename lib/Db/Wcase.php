<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A whistleblowing case. Stores ONLY ciphertext + lifecycle metadata — never
 * plaintext content and never reporter identity data.
 *
 * @method string getLookupHint()
 * @method void setLookupHint(string $v)
 * @method string getCodeHash()
 * @method void setCodeHash(string $v)
 * @method string getCodeSalt()
 * @method void setCodeSalt(string $v)
 * @method string getKeySalt()
 * @method void setKeySalt(string $v)
 * @method string getReporterWrap()
 * @method void setReporterWrap(string $v)
 * @method string|null getTitleCipher()
 * @method void setTitleCipher(?string $v)
 * @method string|null getContactCipher()
 * @method void setContactCipher(?string $v)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method int getLegalHold()
 * @method void setLegalHold(int $v)
 * @method string|null getHoldReason()
 * @method void setHoldReason(?string $v)
 * @method string|null getHoldSetBy()
 * @method void setHoldSetBy(?string $v)
 * @method string|null getAssignedTo()
 * @method void setAssignedTo(?string $v)
 * @method \DateTime getReceivedAt()
 * @method void setReceivedAt(\DateTime $v)
 * @method \DateTime getFeedbackDue()
 * @method void setFeedbackDue(\DateTime $v)
 * @method \DateTime|null getFeedbackSentAt()
 * @method void setFeedbackSentAt(?\DateTime $v)
 * @method \DateTime|null getReminderSentAt()
 * @method void setReminderSentAt(?\DateTime $v)
 * @method \DateTime|null getOverdueNotifiedAt()
 * @method void setOverdueNotifiedAt(?\DateTime $v)
 * @method string|null getCloseRequestedBy()
 * @method void setCloseRequestedBy(?string $v)
 * @method \DateTime|null getClosedAt()
 * @method void setClosedAt(?\DateTime $v)
 * @method string|null getClosedBy()
 * @method void setClosedBy(?string $v)
 * @method \DateTime|null getRetentionUntil()
 * @method void setRetentionUntil(?\DateTime $v)
 * @method int getRetentionWarned()
 * @method void setRetentionWarned(int $v)
 * @method int getCreatedE2e()
 * @method void setCreatedE2e(int $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 */
class Wcase extends Entity
{
	public const STATUS_RECEIVED = 'received';
	public const STATUS_IN_REVIEW = 'in_review';
	public const STATUS_ACTION_TAKEN = 'action_taken';
	public const STATUS_CLOSED = 'closed';
	public const STATUS_ARCHIVED = 'archived';
	/** OVERDUE is a computed flag, never stored (spec §9 state machine). */
	public const FLAG_OVERDUE = 'overdue';

	public const STATUSES = [
		self::STATUS_RECEIVED,
		self::STATUS_IN_REVIEW,
		self::STATUS_ACTION_TAKEN,
		self::STATUS_CLOSED,
		self::STATUS_ARCHIVED,
	];

	protected $lookupHint;
	protected $codeHash;
	protected $codeSalt;
	protected $keySalt;
	protected $reporterWrap;
	protected $titleCipher;
	protected $contactCipher;
	protected $categoryId;
	protected $status;
	protected $legalHold;
	protected $holdReason;
	protected $holdSetBy;
	protected $assignedTo;
	protected $receivedAt;
	protected $feedbackDue;
	protected $feedbackSentAt;
	protected $reminderSentAt;
	protected $overdueNotifiedAt;
	protected $closeRequestedBy;
	protected $closedAt;
	protected $closedBy;
	protected $retentionUntil;
	protected $retentionWarned;
	protected $createdE2e;
	protected $revision;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('categoryId', 'integer');
		$this->addType('legalHold', 'integer');
		$this->addType('retentionWarned', 'integer');
		$this->addType('createdE2e', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('receivedAt', 'datetime');
		$this->addType('feedbackDue', 'datetime');
		$this->addType('feedbackSentAt', 'datetime');
		$this->addType('reminderSentAt', 'datetime');
		$this->addType('overdueNotifiedAt', 'datetime');
		$this->addType('closedAt', 'datetime');
		$this->addType('retentionUntil', 'datetime');
	}

	public function isTerminal(): bool
	{
		return in_array($this->getStatus(), [self::STATUS_CLOSED, self::STATUS_ARCHIVED], true);
	}

	/**
	 * OVERDUE is a computed flag (spec §9): feedback deadline passed, case not
	 * closed and no officer feedback sent yet.
	 */
	public function isOverdue(\DateTimeInterface $today): bool
	{
		if ($this->isTerminal() || $this->getFeedbackSentAt() !== null) {
			return false;
		}
		$due = $this->getFeedbackDue();
		return $due !== null && $due->format('Y-m-d') < $today->format('Y-m-d');
	}

	public function daysUntilDue(\DateTimeInterface $today): ?int
	{
		$due = $this->getFeedbackDue();
		if ($due === null) {
			return null;
		}
		return (int)$today->diff($due)->format('%r%a');
	}
}
