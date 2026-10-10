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

use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\Cms\PortalHelp;
use OCA\Portaliq\Service\Cms\PortalShell;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- one method per kind of content the headless API serves.
 */
class CmsReader {

	/**
	 * The audience-keyed distributed cache.
	 *
	 * @var CmsContentCache
	 */
	private readonly CmsContentCache $cache;

	/**
	 * The scoped read of CMS rows.
	 *
	 * @var CmsRows
	 */
	private readonly CmsRows $rows;

	/**
	 * Shapes the widgets of a page and expands its shared blocks.
	 *
	 * @var CmsSharedBlocks
	 */
	private readonly CmsSharedBlocks $blocks;

	/**
	 * The FAQ entries and the product finder.
	 *
	 * @var CmsFaq
	 */
	private readonly CmsFaq $faqReader;

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
	 *
	 * @return void
	 */
	public function __construct(
		ContainerInterface $container,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger,
		PortalRegisterContext $context,
		private readonly MediaReferences $media,
		private readonly PortalRegionResolver $regions=new PortalRegionResolver(),
		private readonly PortalShell $shell=new PortalShell(),
	) {
		$this->cache     = new CmsContentCache(cache: $cacheFactory->createDistributed('portaliq_cms'), logger: $logger);
		$this->rows      = new CmsRows(container: $container, logger: $logger, context: $context);
		$this->blocks    = new CmsSharedBlocks(rows: $this->rows);
		$this->faqReader = new CmsFaq(cache: $this->cache, rows: $this->rows, reader: $this);
	}//end __construct()


	/**
	 * Read the menus of a portal.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The menus, ordered by position.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function menus(string $portal, string $locale, string $audience): array {
		$key = $this->cache->key(portal: $portal, kind: 'menus', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->lookup(key: $key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->rows->query(schema: 'menu', filters: ['portal' => $portal]);
		usort($rows, static fn ($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));

		$menus = array_map(fn (array $row) => $this->shapeMenu(row: $row), $rows);
		$this->cache->store($key, json_encode($menus));

		return $menus;
	}//end menus()


	/**
	 * Read the published pages of a portal, without their bodies.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The page summaries.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function pages(string $portal, string $locale, string $audience): array {
		$key = $this->cache->key(portal: $portal, kind: 'pages', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->lookup(key: $key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->rows->query(schema: 'page', filters: ['portal' => $portal, 'status' => 'published']);
		$pages = [];
		foreach ($rows as $row) {
			$pages[] = [
				'title'   => (string)($row['title'] ?? ''),
				'route'   => (string)($row['route'] ?? ''),
				'summary' => (string)($row['summary'] ?? ''),
			'helpText' => (new PortalHelp())->pageText(value: ($row['helpText'] ?? null)),
				'locale'  => (string)($row['locale'] ?? ''),
				'bodyType' => (string)($row['body']['type'] ?? ''),
			];
		}

		usort($pages, static fn ($a, $b) => strcmp($a['route'], $b['route']));
		$this->cache->store($key, json_encode($pages));

		return $pages;
	}//end pages()


	/**
	 * Read one published page by route.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $route    The in-site route.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 * @param string $organisation The serving portal's organisation, for shared blocks; '' leaves them unexpanded.
	 *
	 * @return array|null The page, or null when there is no published page there.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t02
	 */
	public function page(string $portal, string $route, string $locale, string $audience, string $organisation=''): ?array {
		// The organisation is part of the key: a placed shared block expands for
		// the organisation it is read for, and a read without one must not
		// hand its unexpanded page to a read with one.
		$selector = $route;
		if ($organisation !== '') {
			$selector = $route . '#org=' . $organisation;
		}

		$key = $this->cache->key(portal: $portal, kind: 'page', selector: $selector, locale: $locale, audience: $audience);
		$hit = $this->cache->lookup(key: $key);
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
		$rows = $this->rows->query(schema: 'page', filters: ['portal' => $portal, 'route' => $route, 'status' => 'published']);
		$page = null;
		foreach ($rows as $row) {
			if ((string)($row['route'] ?? '') === $route) {
				$page = $this->shapePage(row: $row, organisation: $organisation);
				break;
			}
		}

		$this->cache->store($key, json_encode($page ?? []));

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

		$rows = $this->rows->query(schema: 'page', filters: ['portal' => $portal, 'route' => $route]);
		foreach ($rows as $row) {
			if ((string)($row['route'] ?? '') !== $route) {
				continue;
			}

			$id = $this->rows->rowId(row: $row);
			if ($id !== null) {
				return $id;
			}
		}

		return null;
	}//end identify()


	/**
	 * Every row of one schema for a portal, drafts included, for the editor's
	 * publish check. The caller has already established that the person may
	 * edit; this read bypasses RBAC like every other read here.
	 *
	 * @param string $portal The portal slug.
	 * @param string $schema `page`, `menu` or `glossaryTerm`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-2
	 */
	public function rowsForCheck(string $portal, string $schema): array {
		if (in_array($schema, ['page', 'menu', 'glossaryTerm'], true) === false) {
			return [];
		}

		return $this->rows->query(schema: $schema, filters: ['portal' => $portal]);
	}//end rowsForCheck()

	/**
	 * Read the glossary of a portal.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The glossary terms, alphabetical.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function glossary(string $portal, string $locale, string $audience): array {
		$key = $this->cache->key(portal: $portal, kind: 'glossary', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->lookup(key: $key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->rows->query(schema: 'glossaryTerm', filters: ['portal' => $portal]);
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
		$this->cache->store($key, json_encode($terms));

		return $terms;
	}//end glossary()


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
	 * @param array  $row          The stored page.
	 * @param string $organisation The serving portal's organisation, for shared blocks.
	 *
	 * @return array The API shape.
	 */
	private function shapePage(array $row, string $organisation=''): array {
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

		$widgets = $this->blocks->expand(widgets: $this->blocks->shapeWidgets(raw: (array)($body['widgets'] ?? [])), organisation: $organisation);

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
	 * Hits and misses of the content cache since it was first used.
	 *
	 * @return array{hits: int, misses: int}
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function cacheStats(): array {
		return $this->cache->stats();
	}//end cacheStats()

	/**
	 * The cache key of a read: portal, kind, selector, locale and AUDIENCE.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $kind     The kind of content.
	 * @param string $selector The route or other selector, or ''.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function cacheKey(string $portal, string $kind, string $selector, string $locale, string $audience): string {
		return $this->cache->key(portal: $portal, kind: $kind, selector: $selector, locale: $locale, audience: $audience);
	}//end cacheKey()

	/**
	 * Drop every cached entry for a portal.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function invalidate(string $portal): void {
		$this->cache->invalidate(portal: $portal);
	}//end invalidate()

	/**
	 * Read the published FAQ entries of a portal, for one page or one topic or all.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 * @param string $page     Only the entries shown on this page route, or '' for no filter.
	 * @param string $topic    Only the entries of this topic, or '' for no filter.
	 *
	 * @return array The entries: question, answer, topic, pages.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	public function faq(string $portal, string $locale, string $audience, string $page='', string $topic=''): array {
		return $this->faqReader->faq(portal: $portal, locale: $locale, audience: $audience, page: $page, topic: $topic);
	}//end faq()

	/**
	 * Read one published product finder of a portal.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $id       The finder's id, or '' for the first published one.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array|null The finder, or null when there is none published.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	public function finder(string $portal, string $id, string $locale, string $audience): ?array {
		return $this->faqReader->finder(portal: $portal, id: $id, locale: $locale, audience: $audience);
	}//end finder()
}//end class
