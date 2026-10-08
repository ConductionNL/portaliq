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
	 * The filter value an editor sets for "the visitor's own".
	 */
	private const VISITOR = 'visitor';

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
	 * @param string      $app        Only items of this app's index ('' for all).
	 * @param string      $categories Comma-separated categories ('' for all).
	 * @param string      $range      `schoolYear` for only the school year that holds today.
	 *
	 * @return JSONResponse `{items, total, page, pages, facets}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
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
		string $app = '',
		string $categories = '',
		string $range = '',
	): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		$appKey = '';
		if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $app) === 1) {
			$appKey = $app;
		}

		$chosen = json_decode($filters, true);
		if (is_array($chosen) === false) {
			$chosen = [];
		}

		if (in_array($sort, PublicCatalogueQuery::SORTS, true) === false) {
			$sort = 'relevance';
		}

		$chosen   = $this->withVisitorValues(portal: (string)$resolved['slug'], app: $appKey, filters: $chosen);
		$rangeKey = '';
		if ($range === 'schoolYear') {
			$rangeKey = $range;
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
				'app'      => $appKey,
				'categories' => array_values(array_filter(array_map('trim', explode(',', $categories)), static fn (string $category): bool => $category !== '')),
				'range'    => $rangeKey,
				'today'    => date('Y-m-d'),
			]
		);

		return $this->publicJson(payload: $result);
	}//end index()

	/**
	 * What each app declares its public index kinds can be narrowed by and
	 * drawn as (categories, filters, columns), for the editor's block forms.
	 * Gated like the catalogue itself.
	 *
	 * @param string|null $portal The portal slug; else resolved from the request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function kinds(?string $portal = null): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publicJson(payload: ['kinds' => $this->catalogue->kindsFor(portal: (string)$resolved['slug'])]);
	}//end kinds()

	/**
	 * The page of one item of an app's public index: the item with the facts,
	 * sections, dates and documents the app projects for it. Anonymous, gated
	 * like the catalogue, and the shared 404 for an unknown slug or an item the
	 * index does not return.
	 *
	 * @param string|null $portal The portal slug; else resolved from the request.
	 * @param string      $app    The app whose index holds the item.
	 * @param string      $kind   The item's type in the index.
	 * @param string      $slug   The item's slug.
	 *
	 * @return JSONResponse `{item, detail}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function detail(?string $portal = null, string $app = '', string $kind = '', string $slug = ''): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $resolved);
		if ($refusal !== null) {
			return $refusal;
		}

		$found = $this->catalogue->detailFor(portal: (string)$resolved['slug'], appId: $app, kind: $kind, slug: $slug);
		if ($found === null) {
			return $this->notFound();
		}

		return $this->publicJson(payload: $found);
	}//end detail()

	/**
	 * Resolve the filter value `visitor`: for a signed-in person it becomes
	 * that person's own value from the app; for an anonymous one the value is
	 * removed, and a filter left with no value narrows nothing.
	 *
	 * @param string                           $portal  The portal slug.
	 * @param string                           $app     The app whose index is read.
	 * @param array<string, array<int,string>> $filters The chosen filters.
	 *
	 * @return array<string, mixed> The filters with `visitor` resolved.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
	 */
	private function withVisitorValues(string $portal, string $app, array $filters): array {
		$subject  = null;
		$resolved = null;
		foreach ($filters as $label => $values) {
			$values = array_values(array_filter((array)$values, 'is_string'));
			if (in_array(self::VISITOR, $values, true) === false) {
				continue;
			}

			$values = array_values(array_filter($values, static fn (string $value): bool => $value !== self::VISITOR));
			if ($resolved === null) {
				$subject  = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
				$resolved = [];
				if ($subject !== null && $app !== '') {
					$resolved = $this->catalogue->visitorValuesFor(portal: $portal, appId: $app, subject: $subject);
				}
			}

			$values = array_merge($values, ($resolved[$label] ?? []));
			if ($values === []) {
				unset($filters[$label]);
				continue;
			}

			$filters[$label] = array_values(array_unique($values));
		}//end foreach

		return $filters;
	}//end withVisitorValues()

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
