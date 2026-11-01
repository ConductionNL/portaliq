<?php

/**
 * Portaliq Public Content Gate
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Who may read a portal's public content, and how the answer is cached.
 *
 * The using controller provides `$request` and `$session`
 * (PortalSessionService).
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies is the single
 * trust comparator, called statically everywhere so the ordering cannot fork (as in ContentController).
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
trait PublicContentGate {
	/**
	 * Refuse a read the portal does not allow this caller: the same rule as
	 * the rest of the content API (ContentController::refuseUnlessPermitted).
	 * A portal without modes, or with `public`, is readable by anyone.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return JSONResponse|null A refusal, or null when the read may proceed.
	 */
	private function refuseUnlessPermitted(array $portal): ?JSONResponse {
		$auth  = (array)($portal['authentication'] ?? []);
		$modes = array_values(array_filter((array)($auth['modes'] ?? []), 'is_string'));
		if ($modes === [] || in_array('public', $modes, true) === true) {
			return null;
		}

		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return $this->refusal(error: 'authentication_required', status: Http::STATUS_UNAUTHORIZED, modes: $modes);
		}

		if (PortalSessionService::trustSatisfies($subject['trust'] ?? null, ($auth['minTrust'] ?? null)) === false) {
			return $this->refusal(error: 'insufficient_trust', status: Http::STATUS_FORBIDDEN, modes: $modes);
		}

		return null;
	}//end refuseUnlessPermitted()

	/**
	 * A refusal that names the portal's modes, so a renderer can offer the way in.
	 *
	 * @param string             $error  The error code.
	 * @param int                $status The HTTP status.
	 * @param array<int, string> $modes  The portal's modes.
	 *
	 * @return JSONResponse
	 */
	private function refusal(string $error, int $status, array $modes): JSONResponse {
		$response = new JSONResponse(['error' => $error, 'authentication' => ['modes' => $modes]], $status);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end refusal()

	/**
	 * A content response: cacheable for a visitor without a bearer, private otherwise.
	 *
	 * @param array<string, mixed> $payload The body.
	 *
	 * @return JSONResponse
	 */
	private function publicJson(array $payload): JSONResponse {
		$response = new JSONResponse($payload);
		$cache    = 'private, no-store';
		if ((string)$this->request->getHeader('Authorization') === '') {
			$cache = 'public, max-age=300, must-revalidate';
		}

		$response->addHeader('Cache-Control', $cache);

		return $response;
	}//end publicJson()

	/**
	 * The not-found every miss shares, never cached.
	 *
	 * @return JSONResponse A 404.
	 */
	private function notFound(): JSONResponse {
		$missing = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);

		return $missing->addHeader('Cache-Control', 'private, no-store');
	}//end notFound()
}//end trait
