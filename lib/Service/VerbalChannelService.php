<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Service;

/**
 * Verbal-channel scheduling text (spec US-017 / NH-03).
 *
 * Generates neutral wording an officer can paste into a case reply to offer a
 * phone/video appointment — without ever storing appointment details server-
 * side. Content is template text, not case data.
 */
class VerbalChannelService
{
	/** @return array{de:string,en:string} */
	public function offerText(): array
	{
		return [
			'de' => 'Wenn Sie ein mündliches Gespräch wünschen, schlagen Sie bitte '
				. 'zwei Zeitfenster vor. Wir melden uns über diesen Kanal mit einer '
				. 'Bestätigung. Ihre Identität bleibt auch im Gespräch geschützt; '
				. 'Sie müssen Ihren Namen nicht nennen.',
			'en' => 'If you would like a verbal conversation, please propose two '
				. 'time windows. We will confirm via this channel. Your identity '
				. 'stays protected during the call; you do not have to give your name.',
		];
	}
}
