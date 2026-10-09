<?php

/**
 * Portaliq Content Catalogue Controller (portal-public-catalogue)
 *
 * `GET /api/content/catalogue`: one page of a portal's public catalogue (its
 * public news and every app's public index), searched, filtered by facet and
 * sorted, for a visitor who need not sign in. Gated like the rest of
 * `/api/content`: a portal whose sign-in modes do not include `public`
 * answers only a session that meets its trust.
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
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicCatalogueQuery;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves a portal's public catalogue.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies,
 * the one trust ordering every portal gate shares.
 */
class ContentCatalogueController extends Controller {
	/**
	 * The longest query.
	 */
	private const MAX_QUERY = 200;

	/**
	 * Constructor.
	 *
	 * @param string               $appName   The app name.
	 * @param IRequest             $request   The request.
	 * @param PortalResolver       $resolver  Resolves the portal of the request.
	 * @param PortalSessionService $session   Resolves a bearer, for a portal that is not public.
	 * @param PublicCatalogue      $catalogue The catalogue.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly PortalSessionService $session,
		private readonly PublicCatalogue $catalogue,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * One page of the portal's catalogue.
	 *
	 * @param string|null $portal   The portal slug.
	 * @param string      $search   The words searched for.
	 * @param string      $types    Comma-separated item types ('' for all).
	 * @param string      $filters  The facet choices, as JSON `{label: [values]}`.
	 * @param string      $sort     `relevance`, `date`, `dateDesc` or `title`.
	 * @param int         $page     The page, from 1.
	 * @param int         $limit    Results per page, at most 50.
	 * @param string      $upcoming `1` for only the items whose date is today or later.
	 * @param string      $facetsBy The facets to add, as JSON `{kind, news, audience}`: the label of a facet by item kind ("Soort"), a news item's kind word in it ("Nieuws"), and the label of a facet by a news item's audience ("Voor wie").
	 *
	 * @return JSONResponse `{items, total, page, pages, facets}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(
		?string $portal = null,
		string $search = '',
		string $types = '',
		string $filters = '',
		string $sort = 'relevance',
		int $page = 1,
		int $limit = 10,
		string $upcoming = '',
		string $facetsBy = '',
	): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		$chosen = json_decode($filters, true);
		if (is_array($chosen) === false) {
			$chosen = [];
		}

		$derive = json_decode($facetsBy, true);
		if (is_array($derive) === false) {
			$derive = [];
		}

		if (in_array($sort, PublicCatalogueQuery::SORTS, true) === false) {
			$sort = 'relevance';
		}

		$result = (new PublicCatalogueQuery())->run(
			items: $this->catalogue->itemsFor(portal: (string)$resolved['slug']),
			params: [
				'q'        => mb_substr($search, 0, self::MAX_QUERY),
				'types'    => array_values(array_filter(array_map('trim', explode(',', $types)), static fn (string $type): bool => $type !== '')),
				'filters'  => $chosen,
				'sort'     => $sort,
				'page'     => $page,
				'limit'    => $limit,
				'upcoming' => ($upcoming === '1'),
				'today'         => date('Y-m-d'),
				'kindFacet'     => ($derive['kind'] ?? ''),
				'kindNews'      => ($derive['news'] ?? ''),
				'audienceFacet' => ($derive['audience'] ?? ''),
			]
		);

		return $this->publicJson(payload: $result);
	}//end index()

	/**
	 * Null when the portal is public or the session meets its trust; else
	 * the refusal, with the portal's ways in.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return JSONResponse|null
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
	 * A refusal that is never cached.
	 *
	 * @param string             $error  The error code.
	 * @param int                $status The status.
	 * @param array<int, string> $modes  The portal's ways in.
	 *
	 * @return JSONResponse
	 */
	private function refusal(string $error, int $status, array $modes): JSONResponse {
		$response = new JSONResponse(['error' => $error, 'authentication' => ['modes' => $modes]], $status);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end refusal()

	/**
	 * The answer, cacheable for a visitor who sent no bearer.
	 *
	 * @param array<string, mixed> $payload The answer.
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
	 * The shared 404.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end notFound()
}//end class
