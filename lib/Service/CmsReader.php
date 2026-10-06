<?php

/**
 * Portaliq CMS Reader
 *
 * Reads portal-scoped CMS content (menus, pages, glossary) for the headless
 * content API (ADR-086 §§1, 3, 4, 5, 9).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Cms\ContentLocale;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\Cms\PortalShell;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the CMS content of ONE portal.
 *
 * Every method takes the portal slug and filters on it. There is no
 * "read all pages" — an unscoped content query is the bug this class exists
 * to make impossible to write by accident.
 *
 * CACHING. Responses are cached under a key of
 * `portal + kind + route + locale + AUDIENCE`. The audience component is the
 * one that carries risk: without it, a page rendered for a signed-in visitor
 * is served to everyone who asks for the same URL. The unit test for this is
 * written so that removing the audience component makes it FAIL — the check is
 * only worth having if it has been seen to fail.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */
class CmsReader {

	/**
	 * OpenRegister's ObjectService FQCN, resolved lazily from the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Cache lifetime for a public content read, in seconds.
	 *
	 * Deliberately modest: invalidation is event-driven on the content
	 * object's write, and this TTL is only the backstop for a missed event.
	 * An editor who publishes should never have to wait it out.
	 *
	 * @var int
	 */
	private const TTL = 300;

	/**
	 * The distributed cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container    For the lazy OpenRegister lookup.
	 * @param ICacheFactory         $cacheFactory Creates the distributed cache.
	 * @param LoggerInterface       $logger       The logger.
	 * @param PortalRegisterContext $context      Points the shared ObjectService at this app's schemas.
	 * @param MediaReferences       $media        Resolves a page's media:<id> references.
	 * @param PortalRegionResolver  $regions      Groups a page's widgets by region.
	 * @param PortalShell           $shell        Projects the portal's header, footer and regions.
	 * @param ContentLocale         $locales      Chooses the rows in the visitor's language.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
		private readonly MediaReferences $media,
		private readonly PortalRegionResolver $regions=new PortalRegionResolver(),
		private readonly PortalShell $shell=new PortalShell(),
		private readonly ContentLocale $locales=new ContentLocale(),
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_cms');
	}//end __construct()


	/**
	 * Build the cache key for a content read.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $kind     What is being read (menus, page, pages, glossary).
	 * @param string $selector The route or other selector, '' when not applicable.
	 * @param string $locale   The locale.
	 * @param string $audience 'anonymous' or the authenticated audience.
	 *
	 * @return string The cache key.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function cacheKey(string $portal, string $kind, string $selector, string $locale, string $audience): string {
		// `audience` is NOT optional and NOT last-by-accident. Dropping it is
		// the single change that turns this cache into a cross-visitor data
		// leak, so it is part of the key's identity, not a suffix.
		return implode(
			'|',
			[$portal, $kind, $selector, $locale, $audience]
		);
	}//end cacheKey()


	/**
	 * Read the menus of a portal, in one locale.
	 *
	 * Menus are translated as a set: the requested locale's menus when it has
	 * any, else the default locale's, plus every menu without a locale.
	 *
	 * @param string $portal        The portal slug.
	 * @param string $locale        The locale.
	 * @param string $audience      The requesting audience.
	 * @param string $defaultLocale The portal's default locale (its first), '' when unknown.
	 *
	 * @return array The menus, ordered by position.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
	 */
	public function menus(string $portal, string $locale, string $audience, string $defaultLocale=''): array {
		$key = $this->cacheKey(portal: $portal, kind: 'menus', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->locales->filter(
			rows: $this->query(schema: 'menu', filters: ['portal' => $portal]),
			locale: $locale,
			defaultLocale: $defaultLocale
		);
		usort($rows, static fn ($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));

		$menus = array_map(fn (array $row) => $this->shapeMenu(row: $row), $rows);
		$this->cache->set($key, json_encode($menus), self::TTL);

		return $menus;
	}//end menus()


	/**
	 * Read the published pages of a portal, without their bodies.
	 *
	 * One summary per route, in the language {@see page()} would serve there.
	 *
	 * @param string $portal        The portal slug.
	 * @param string $locale        The locale.
	 * @param string $audience      The requesting audience.
	 * @param string $defaultLocale The portal's default locale (its first), '' when unknown.
	 *
	 * @return array The page summaries.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
	 */
	public function pages(string $portal, string $locale, string $audience, string $defaultLocale=''): array {
		$key = $this->cacheKey(portal: $portal, kind: 'pages', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$byRoute = [];
		foreach ($this->query(schema: 'page', filters: ['portal' => $portal, 'status' => 'published']) as $row) {
			$byRoute[(string)($row['route'] ?? '')][] = $row;
		}

		$pages = [];
		foreach ($byRoute as $candidates) {
			$row = $this->locales->pick(rows: $candidates, locale: $locale, defaultLocale: $defaultLocale);
			$pages[] = [
				'title'   => (string)($row['title'] ?? ''),
				'route'   => (string)($row['route'] ?? ''),
				'summary' => (string)($row['summary'] ?? ''),
				'locale'  => (string)($row['locale'] ?? ''),
				'bodyType' => (string)($row['body']['type'] ?? ''),
			];
		}

		usort($pages, static fn ($a, $b) => strcmp($a['route'], $b['route']));
		$this->cache->set($key, json_encode($pages), self::TTL);

		return $pages;
	}//end pages()


	/**
	 * Read one published page by route.
	 *
	 * A route can hold one page per language. The page in the requested locale
	 * is served; failing that the one in the portal's default locale, then one
	 * without a locale, then whichever there is.
	 *
	 * @param string $portal        The portal slug.
	 * @param string $route         The in-site route.
	 * @param string $locale        The locale.
	 * @param string $audience      The requesting audience.
	 * @param string $defaultLocale The portal's default locale (its first), '' when unknown.
	 *
	 * @return array|null The page, or null when there is no published page there.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
	 */
	public function page(string $portal, string $route, string $locale, string $audience, string $defaultLocale=''): ?array {
		$key = $this->cacheKey(portal: $portal, kind: 'page', selector: $route, locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			$decoded = json_decode($hit, true);
			if ($decoded === []) {
				return null;
			}

			return $decoded;
		}

		// The `status` filter is applied in the QUERY, not after. A draft page
		// must never reach this process's memory, let alone a response: an
		// unpublished route and a non-existent route are answered identically,
		// so the API is not an existence oracle for unreleased content.
		$rows = $this->query(schema: 'page', filters: ['portal' => $portal, 'route' => $route, 'status' => 'published']);

		// The route is re-checked here, not trusted to the filter: a filter
		// that silently widened would serve a DIFFERENT page under this route.
		$atRoute = array_filter($rows, static fn (array $row): bool => (string)($row['route'] ?? '') === $route);
		$chosen  = $this->locales->pick(rows: array_values($atRoute), locale: $locale, defaultLocale: $defaultLocale);
		$page    = null;
		if ($chosen !== null) {
			$page = $this->shapePage(row: $chosen);
		}

		$this->cache->set($key, json_encode($page ?? []), self::TTL);

		return $page;
	}//end page()


	/**
	 * Resolve the stored identifier of a page at a route, published or not.
	 *
	 * FOR EDITORS ONLY, and the caller is what makes that true. Everything else
	 * on this class answers a PUBLIC question and filters `status` in the query
	 * so a draft never reaches this process; this method deliberately does not,
	 * because an editor's whole reason to ask is to open the draft. The
	 * `_rbac: false` read underneath it therefore has no authorization of its
	 * own — {@see \OCA\Portaliq\Controller\CmsEditorController::editingContext()}
	 * refuses before calling here, and this method must never be reached from a
	 * path that does not.
	 *
	 * Nothing is cached. An editor who has just created a page and is looking
	 * for the way into it is precisely the caller a stale negative would strand.
	 *
	 * @param string $portal The portal slug.
	 * @param string $route  The in-site route, leading slash included.
	 *
	 * @return string|null The object identifier, or null when no page is there.
	 *
	 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function identify(string $portal, string $route): ?string {
		if ($portal === '' || $route === '') {
			return null;
		}

		$rows = $this->query(schema: 'page', filters: ['portal' => $portal, 'route' => $route]);
		foreach ($rows as $row) {
			if ((string)($row['route'] ?? '') !== $route) {
				continue;
			}

			$id = $this->rowId(row: $row);
			if ($id !== null) {
				return $id;
			}
		}

		return null;
	}//end identify()


	/**
	 * The identifier of a stored row, flat or inside the `@self` envelope.
	 *
	 * Both shapes are read because both occur: OpenRegister's object API
	 * returns a flat `id` alongside the envelope, and a row that has been
	 * projected or re-serialised elsewhere may carry only one of them.
	 *
	 * @param array $row The stored row.
	 *
	 * @return string|null The identifier, or null when the row carries none.
	 */
	private function rowId(array $row): ?string {
		$self = ($row['@self'] ?? null);
		$candidates = [
			($row['id'] ?? null),
			($row['uuid'] ?? null),
		];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
			$candidates[] = ($self['uuid'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end rowId()


	/**
	 * Read the glossary of a portal, in one locale.
	 *
	 * Translated as a set, like the menus: the requested locale's terms when
	 * it has any, else the default locale's, plus every term without a locale.
	 *
	 * @param string $portal        The portal slug.
	 * @param string $locale        The locale.
	 * @param string $audience      The requesting audience.
	 * @param string $defaultLocale The portal's default locale (its first), '' when unknown.
	 *
	 * @return array The glossary terms, alphabetical.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
	 */
	public function glossary(string $portal, string $locale, string $audience, string $defaultLocale=''): array {
		$key = $this->cacheKey(portal: $portal, kind: 'glossary', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->locales->filter(
			rows: $this->query(schema: 'glossaryTerm', filters: ['portal' => $portal]),
			locale: $locale,
			defaultLocale: $defaultLocale
		);
		$terms = [];
		foreach ($rows as $row) {
			$terms[] = [
				'term'       => (string)($row['term'] ?? ''),
				'definition' => (string)($row['definition'] ?? ''),
				'synonyms'   => array_values((array)($row['synonyms'] ?? [])),
				'source'     => (string)($row['source'] ?? ''),
			];
		}

		usort($terms, static fn ($a, $b) => strcasecmp($a['term'], $b['term']));
		$this->cache->set($key, json_encode($terms), self::TTL);

		return $terms;
	}//end glossary()


	/**
	 * Drop every cached entry for a portal.
	 *
	 * Invalidation is event-driven, not expiry-driven: an editor who publishes
	 * and then has to wait out a TTL will conclude the CMS is broken, and will
	 * be right.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function invalidate(string $portal): void {
		// Prefix clear covers everything for this site, INCLUDING per-route
		// page entries, whose keys are not enumerable from here. That matters
		// more than it looks: the page cache stores negative results too, so a
		// missed invalidation leaves a newly created route 404ing for the rest
		// of the TTL while the object plainly exists.
		$this->cache->clear($portal . '|');

		// Belt and braces for backends whose clear() ignores the prefix: the
		// keys that can be named are removed by name as well. Cheap, and the
		// alternative failure is invisible until someone reports stale content.
		foreach (['menus', 'pages', 'glossary'] as $kind) {
			foreach (['anonymous', 'authenticated'] as $audience) {
				foreach (['', 'nl', 'en'] as $locale) {
					$this->cache->remove($this->cacheKey(portal: $portal, kind: $kind, selector: '', locale: $locale, audience: $audience));
				}
			}
		}
	}//end invalidate()


	/**
	 * Shape a stored menu row for the API.
	 *
	 * @param array $row The stored menu.
	 *
	 * @return array The API shape.
	 */
	private function shapeMenu(array $row): array {
		$items = [];
		foreach ((array)($row['items'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$children = [];
			foreach ((array)($item['items'] ?? []) as $child) {
				if (is_array($child) === false) {
					continue;
				}

				// Only ONE level of children is emitted. A third level in
				// stored data is dropped here rather than passed on, so a
				// consumer never has to guess how deep the tree can go.
				$children[] = [
					'order' => (int)($child['order'] ?? 0),
					'name'  => (string)($child['name'] ?? ''),
					'link'  => (string)($child['link'] ?? ''),
					'icon'  => (string)($child['icon'] ?? ''),
				];
			}

			usort($children, static fn ($a, $b) => $a['order'] <=> $b['order']);

			$items[] = [
				'order'       => (int)($item['order'] ?? 0),
				'name'        => (string)($item['name'] ?? ''),
				'link'        => (string)($item['link'] ?? ''),
				'description' => (string)($item['description'] ?? ''),
				'icon'        => (string)($item['icon'] ?? ''),
				'items'       => $children,
			];
		}

		usort($items, static fn ($a, $b) => $a['order'] <=> $b['order']);

		return [
			'title'    => (string)($row['title'] ?? ''),
			'position' => (int)($row['position'] ?? 0),
			'items'    => $items,
		];
	}//end shapeMenu()


	/**
	 * A page's search-engine fields, stored flat as `seo*` so the page form
	 * shows them, served as one `seo` object to the API and the head
	 * (site-page-seo-history-and-media). Missing fields are empty, never absent.
	 *
	 * @param array $row The stored page.
	 *
	 * @return array{title: string, description: string, noindex: bool, image: string}
	 */
	private function shapeSeo(array $row): array {
		$portal = (string)($row['portal'] ?? '');

		return [
			'title'       => (string)($row['seoTitle'] ?? ''),
			'description' => (string)($row['seoDescription'] ?? ''),
			'noindex'     => (($row['seoNoindex'] ?? false) === true),
			'image'       => $this->media->image(portal: $portal, value: (string)($row['seoImage'] ?? '')),
		];
	}//end shapeSeo()


	/**
	 * The portal's shell as the public site contract serves it.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `authentication`, `headerVariant`, `footer` and `regions`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 */
	public function shell(array $portal): array {
		return $this->shell->project(portal: $portal);
	}//end shell()


	/**
	 * Shape a stored page row for the API.
	 *
	 * @param array $row The stored page.
	 *
	 * @return array The API shape.
	 */
	private function shapePage(array $row): array {
		$body   = (array)($row['body'] ?? []);
		$type   = (string)($body['type'] ?? 'markdown');
		$portal = (string)($row['portal'] ?? '');

		$shaped = [
			'title'   => (string)($row['title'] ?? ''),
			'route'   => (string)($row['route'] ?? ''),
			'summary' => (string)($row['summary'] ?? ''),
			'locale'  => (string)($row['locale'] ?? ''),
			'seo'     => $this->shapeSeo(row: $row),
			'hero'    => $this->media->hero(portal: $portal, value: ($row['heroImage'] ?? null)),
			'body'    => ['type' => $type],
		];

		// The regions this page empties on purpose (REQ-PTB-009). Served for
		// both body types: a markdown page can clear the portal's hero too.
		$shaped['body']['clearedRegions'] = $this->regions->cleared(cleared: ($body['clearedRegions'] ?? []));

		if ($type === 'markdown') {
			// Served as SOURCE. Rendering to HTML here would force every
			// consumer that wants markdown — a Docusaurus build, most
			// obviously — to parse it back out, losing fidelity for nothing.
			// Only a media:<id> link target is rewritten to the item's address.
			$shaped['body']['markdown'] = $this->media->markdown(portal: $portal, markdown: (string)($body['markdown'] ?? ''));
			return $shaped;
		}

		$widgets = [];
		foreach ((array)($body['widgets'] ?? []) as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			$widgets[] = [
				'id'         => (string)($widget['id'] ?? ''),
				'widgetKey'  => (string)($widget['widgetKey'] ?? ''),
				'slot'       => (string)($widget['slot'] ?? 'body'),
				'gridX'      => (int)($widget['gridX'] ?? 0),
				'gridY'      => (int)($widget['gridY'] ?? 0),
				'gridWidth'  => (int)($widget['gridWidth'] ?? 12),
				'gridHeight' => (int)($widget['gridHeight'] ?? 4),
				'props'      => (array)($widget['props'] ?? []),
			];
		}

		usort(
			$widgets,
			static fn ($a, $b) => [$a['gridY'], $a['gridX']] <=> [$b['gridY'], $b['gridX']]
		);

		$shaped['body']['widgets'] = $widgets;

		// The same widgets grouped by region, beside the flat list the
		// Docusaurus plugin reads (REQ-PTB-008). A slot that names no region
		// is reported, not dropped silently. An empty map stays an object.
		$grouped = $this->regions->group(widgets: $widgets);
		$shaped['body']['regions']        = $this->regions->forJson(regions: $grouped['regions']);
		$shaped['body']['unknownRegions'] = $grouped['unknownRegions'];

		return $shaped;
	}//end shapePage()


	/**
	 * Query one CMS schema with the given property filters.
	 *
	 * @param string $schema  The schema slug.
	 * @param array  $filters The property filters, always including `portal`.
	 *
	 * @return array The rows, as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		if (($filters['portal'] ?? '') === '') {
			// Refusing here rather than returning everything: an unscoped read
			// would silently serve one site's content under another's domain,
			// and the response would look entirely normal.
			$this->logger->error('Portaliq: refusing an unscoped CMS query', ['schema' => $schema]);
			return [];
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			// Through the context helper, never through two slug setters: the
			// slug form re-resolves whatever schema ref another app left
			// pending on the shared ObjectService, and that took every content
			// read here down with a slug this app does not own. See
			// PortalRegisterContext.
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return [];
			}

			$rows = $objectService->findAll(
				config: ['filters' => $filters, 'limit' => 500, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: CMS read failed',
				['schema' => $schema, 'reason' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_map(
			static function ($row) {
				if (is_array($row) === true) {
					return $row;
				}

				return (array)$row->jsonSerialize();
			},
			$rows
		);
	}//end query()


}//end class
