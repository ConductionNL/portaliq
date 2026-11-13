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

use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PublicCatalogueInterface;
use OCA\Portaliq\Kernel\Contracts\PublicCatalogueQueryInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use TypeError;

/**
 * Serves a portal's public catalogue.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- index() takes one query parameter per catalogue
 * filter, bound by name from the route.
 */
class ContentCatalogueController extends Controller {
	use PublicContentGate;
	use BearerCachedJson;

	/**
	 * The longest query.
	 */
	private const MAX_QUERY = 200;

	/**
	 * The filter value an editor sets for "the visitor's own".
	 */
	private const VISITOR = 'visitor';

	/**
	 * The query's text values and their defaults: search words, comma-separated
	 * types, JSON filters, the sort, `1` for upcoming only, the app key,
	 * comma-separated categories, the range, and JSON naming the derived facets.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_PARAMS = [
		'search' => '',
		'types' => '',
		'filters' => '',
		'sort' => 'relevance',
		'upcoming' => '',
		'app' => '',
		'categories' => '',
		'range' => '',
		'facetsBy' => '',
	];

	/**
	 * Constructor.
	 *
	 * @param string               $appName   The app name.
	 * @param IRequest             $request   The request.
	 * @param PortalResolverInterface       $resolver  Resolves the portal of the request.
	 * @param PortalSessionServiceInterface $session   Resolves a bearer, for a portal that is not public.
	 * @param PublicCatalogueInterface      $catalogue The catalogue.
	 * @param PublicCatalogueQueryInterface $publicCatalogueQuery The PublicCatalogueQuery helper.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolverInterface $resolver,
		private readonly PortalSessionServiceInterface $session,
		private readonly PublicCatalogueInterface $catalogue,
		private readonly PublicCatalogueQueryInterface $publicCatalogueQuery,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * One page of the portal's catalogue.
	 *
	 * The request carries the query: `search`, `types` (comma-separated, '' for
	 * all), `filters` (JSON `{label: [values]}`), `sort` (`relevance`, `date`,
	 * `dateDesc` or `title`), `page` (from 1), `limit` (at most 50), `upcoming`
	 * (`1` for only the items whose date is today or later), `app` (only items of
	 * this app's index), `categories` (comma-separated), `range` (`schoolYear` for
	 * only the school year that holds today) and `facetsBy` (JSON `{kind, news,
	 * audience}`: a facet by kind, a news item's kind in it, a facet by a news
	 * item's audience).
	 *
	 * @param string|null $portal The portal slug.
	 *
	 * @return JSONResponse `{items, total, page, pages, facets}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(?string $portal = null): JSONResponse {
		$asked    = $this->askedQuery();
		$resolved = $this->permittedPortal(portal: $portal);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		$slug   = (string)$resolved['slug'];
		$params = $this->queryParams(asked: $asked, slug: $slug);
		$result = $this->publicCatalogueQuery->run(items: $this->catalogue->itemsFor(portal: $slug), params: $params);

		return $this->publicJson(payload: $result);
	}//end index()

	/**
	 * The query values as the request carries them, with the defaults of an
	 * empty request. A value of the wrong type is a TypeError, before anything
	 * is resolved or read.
	 *
	 * @return array<string, mixed>
	 */
	private function askedQuery(): array {
		$asked = [];
		foreach (self::TEXT_PARAMS as $name => $default) {
			$asked[$name] = $this->textParam(name: $name, default: $default);
		}

		$asked['page']  = $this->numberParam(name: 'page', default: 1);
		$asked['limit'] = $this->numberParam(name: 'limit', default: 10);

		return $asked;
	}//end askedQuery()

	/**
	 * One text value of the request.
	 *
	 * @param string $name    The value's name.
	 * @param string $default The value when the request has none.
	 *
	 * @return string The value.
	 */
	private function textParam(string $name, string $default): string {
		return $this->request->getParam($name, $default);
	}//end textParam()

	/**
	 * One whole number of the request, cast as the framework casts an int parameter.
	 *
	 * @param string $name    The value's name.
	 * @param int    $default The value when the request has none.
	 *
	 * @return int The value.
	 */
	private function numberParam(string $name, int $default): int {
		$value = $this->request->getParam($name, $default);
		if ($value === null) {
			throw new TypeError($name . ' must not be null');
		}

		return (int)$value;
	}//end numberParam()

	/**
	 * What the catalogue query is asked, sanitised, with the visitor's own
	 * values in place of the visitor token.
	 *
	 * @param array<string, mixed> $asked The values from askedQuery().
	 * @param string               $slug  The resolved portal's slug.
	 *
	 * @return array<string, mixed> The query's parameters.
	 */
	private function queryParams(array $asked, string $slug): array {
		$appKey = '';
		if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $asked['app']) === 1) {
			$appKey = $asked['app'];
		}

		$derive = self::jsonArray(text: $asked['facetsBy']);

		return [
			'q'        => mb_substr($asked['search'], 0, self::MAX_QUERY),
			'types'    => self::commaList(text: $asked['types']),
			'filters'  => $this->withVisitorValues(portal: $slug, app: $appKey, filters: self::jsonArray(text: $asked['filters'])),
			'sort'     => self::sortKey(sort: $asked['sort']),
			'page'     => $asked['page'],
			'limit'    => $asked['limit'],
			'upcoming' => ($asked['upcoming'] === '1'),
			'app'      => $appKey,
			'categories' => self::commaList(text: $asked['categories']),
			'range'    => self::rangeKey(range: $asked['range']),
			'today'         => date('Y-m-d'),
			'kindFacet'     => ($derive['kind'] ?? ''),
			'kindNews'      => ($derive['news'] ?? ''),
			'audienceFacet' => ($derive['audience'] ?? ''),
		];
	}//end queryParams()

	/**
	 * The sort asked for, when the query knows it.
	 *
	 * @param string $sort The sort asked for.
	 *
	 * @return string The sort.
	 */
	private static function sortKey(string $sort): string {
		if (in_array($sort, PublicCatalogueQueryInterface::SORTS, true) === true) {
			return $sort;
		}

		return 'relevance';
	}//end sortKey()

	/**
	 * The range asked for, when the query knows it.
	 *
	 * @param string $range The range asked for.
	 *
	 * @return string The range, or ''.
	 */
	private static function rangeKey(string $range): string {
		if ($range === 'schoolYear') {
			return $range;
		}

		return '';
	}//end rangeKey()

	/**
	 * A JSON text as an array; anything else is empty.
	 *
	 * @param string $text The text.
	 *
	 * @return array<mixed>
	 */
	private static function jsonArray(string $text): array {
		$decoded = json_decode($text, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end jsonArray()

	/**
	 * A comma-separated text as its trimmed, non-empty entries.
	 *
	 * @param string $text The text.
	 *
	 * @return array<int, string>
	 */
	private static function commaList(string $text): array {
		return array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $entry): bool => $entry !== ''));
	}//end commaList()

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
		$resolved = $this->permittedPortal(portal: $portal);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
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
		$resolved = $this->permittedPortal(portal: $portal);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
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
}//end class
