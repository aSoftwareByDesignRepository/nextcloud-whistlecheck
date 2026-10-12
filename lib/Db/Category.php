<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Report category. `labelJson` holds {"de": "...", "en": "..."} — org-provided
 * plain text (not report content). Categories are archived, never deleted,
 * while cases reference them (spec US-008 AC-3).
 *
 * @method string getLabelJson()
 * @method void setLabelJson(string $v)
 * @method int getArchived()
 * @method void setArchived(int $v)
 * @method int getSort()
 * @method void setSort(int $v)
 */
class Category extends Entity
{
	protected $labelJson;
	protected $archived;
	protected $sort;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('archived', 'integer');
		$this->addType('sort', 'integer');
	}
}
