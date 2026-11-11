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

use OCA\Portaliq\Service\Session\BearerSessionResolver;
use OCA\Portaliq\Service\Session\SessionTrust;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Who may read a portal's public content, and the answers that refuse it.
 *
 * The using controller provides `$request`, `$session`
 * (BearerSessionResolver) and `$resolver` (PortalResolver).
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
trait PublicContentGate {
	/**
	 * The portal of this request when this caller may read its content, else
	 * the answer that refuses: the shared 404 for an unknown portal, or the
	 * gate's 401 / 403.
	 *
	 * @param string|null $portal The portal slug; else resolved from the request.
	 *
	 * @return array<string, mixed>|JSONResponse The resolved portal, or the refusal.
	 */
	private function permittedPortal(string|null $portal): array|JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		return ($this->refuseUnlessPermitted(portal: $resolved) ?? $resolved);
	}//end permittedPortal()

	/**
	 * Refuse a CONTENT read the resolved portal does not allow this caller.
	 *
	 * The door is public, the rooms are not: a portal's own presentation
	 * record stays readable so a visitor can see how to sign in, while
	 * everything that carries content is gated here.
	 *
	 * Fails closed to public read-only when the block is absent or
	 * unparseable, which is what the schema documents: a portal without modes,
	 * or with `public`, is readable by anyone.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return JSONResponse|null A refusal, or null when the read may proceed.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	private function refuseUnlessPermitted(array $portal): ?JSONResponse {
		$auth  = (array)($portal['authentication'] ?? []);
		$modes = array_values(array_filter((array)($auth['modes'] ?? []), 'is_string'));
		if ($modes === [] || in_array('public', $modes, true) === true) {
			return null;
		}

		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			// 401, not 404: the portal's presentation record already tells an
			// anonymous caller it exists and how to sign in, and the modes are
			// echoed so the renderer can offer that way in.
			return $this->refusal(error: 'authentication_required', status: Http::STATUS_UNAUTHORIZED, modes: $modes);
		}

		if ((new SessionTrust())->trustSatisfies($subject['trust'] ?? null, ($auth['minTrust'] ?? null)) === false) {
			// A real session that is not assured enough. Distinct from 401:
			// signing in again with the same means would loop.
			return $this->refusal(error: 'insufficient_trust', status: Http::STATUS_FORBIDDEN, modes: $modes);
		}

		return null;
	}//end refuseUnlessPermitted()

	/**
	 * A refusal that names the portal's modes, so a renderer can offer the way in.
	 *
	 * `private, no-store` is load-bearing: a refusal for a non-public portal
	 * must never be pooled at a CDN, or the first anonymous miss becomes the
	 * answer every later visitor gets, including the ones who signed in.
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
	 * The single not-found response every miss shares, never cached.
	 *
	 * @return JSONResponse A 404.
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end notFound()
}//end trait
