<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Middleware;

use OCA\WhistleCheck\Controller\PublicApiController;
use OCA\WhistleCheck\Controller\PublicPageController;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;

/**
 * Reporters get no cookies — the channel spec (NN-05/§6) forbids any
 * identifying state on the anonymous surface.
 *
 * Core session middleware starts a session for any request reaching these
 * controllers, which queues `Set-Cookie` through PHP's header list before
 * our controller ever runs. `header_remove('Set-Cookie')` runs after the
 * controller — before the response is flushed — so the queued session,
 * passphrase and samesite-probe cookies never reach the wire. Response-level
 * cookies are cleared the same way. Officer pages keep their session —
 * this middleware only fires for the two public controllers.
 *
 * The public flow is intentionally session-free: form tokens are DB-backed
 * (`wchk_form_tokens`, bucket-bound), so stripping the cookie loses nothing.
 */
final class PublicCookieFreeMiddleware extends Middleware
{
	public function afterController($controller, $methodName, Response $response): Response
	{
		if ($controller instanceof PublicPageController || $controller instanceof PublicApiController) {
			$response->setCookies([]);
			if (!headers_sent()) {
				header_remove('Set-Cookie');
			}
		}
		return $response;
	}
}
