<?php

/**
 * Portaliq Content News Controller
 *
 * The public news of one portal, on the headless content contract
 * (site-school-blocks): the list a website's news widget shows, and one item
 * for the article page. Public like the rest of `/api/content`, gated by the
 * portal's declared sign-in modes the same way, and cacheable for a visitor
 * who is not signed in.
 *
 * Which items, and what of them, is PublicNewsReader's decision: published,
 * put on this portal's website by staff, never an item about single
 * children, and only photos from this portal's own published media library.
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

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves a portal's public news.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies is the single
 * trust comparator, called statically everywhere so the ordering cannot fork (as in ContentController).
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
class ContentNewsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string               $appName  The app id.
	 * @param IRequest             $request  The request.
	 * @param PortalResolver       $resolver Resolves the serving portal.
	 * @param PortalSessionService $session  Resolves the caller's portal session for the content gate.
	 * @param PublicNewsReader     $news     Reads the portal's public news.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly PortalSessionService $session,
		private readonly PublicNewsReader $news,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The newest public items of the resolved portal.
	 *
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 * @param int|null    $limit  How many, 1 to 12; 4 when absent.
	 *
	 * @return JSONResponse `{items: []}`, a 401/403 refusal, or 404.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(?string $portal = null, ?int $limit = null): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publicJson(
			payload: ['items' => $this->news->listFor(portal: (string)$resolved['slug'], limit: ($limit ?? 4))]
		);
	}//end index()

	/**
	 * One public item of the resolved portal.
	 *
	 * @param string      $id     The item id.
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 *
	 * @return JSONResponse `{item}`, a 401/403 refusal, or 404 for anything that is not a public item of this portal.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function show(string $id, ?string $portal = null): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		$item = $this->news->itemFor(portal: (string)$resolved['slug'], id: $id);
		if ($item === null) {
			return $this->notFound();
		}

		return $this->publicJson(payload: ['item' => $item]);
	}//end show()

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
	 * The not-found every miss shares.
	 *
	 * @return JSONResponse A 404.
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end notFound()
}//end class
