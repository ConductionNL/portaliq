<?php

/**
 * Portaliq CMS Reader
 *
 * Reads website-scoped CMS content (menus, pages, glossary) for the headless
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the CMS content of ONE website.
 *
 * Every method takes the website slug and filters on it. There is no
 * "read all pages" — an unscoped content query is the bug this class exists
 * to make impossible to write by accident.
 *
 * CACHING. Responses are cached under a key of
 * `website + kind + route + locale + AUDIENCE`. The audience component is the
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
	 * The register the CMS schemas live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'portaliq';

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
	 * @param ContainerInterface $container    For the lazy OpenRegister lookup.
	 * @param ICacheFactory      $cacheFactory Creates the distributed cache.
	 * @param LoggerInterface    $logger       The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_cms');
	}//end __construct()


	/**
	 * Build the cache key for a content read.
	 *
	 * @param string $website  The website slug.
	 * @param string $kind     What is being read (menus, page, pages, glossary).
	 * @param string $selector The route or other selector, '' when not applicable.
	 * @param string $locale   The locale.
	 * @param string $audience 'anonymous' or the authenticated audience.
	 *
	 * @return string The cache key.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function cacheKey(string $website, string $kind, string $selector, string $locale, string $audience): string {
		// `audience` is NOT optional and NOT last-by-accident. Dropping it is
		// the single change that turns this cache into a cross-visitor data
		// leak, so it is part of the key's identity, not a suffix.
		return implode(
			'|',
			[$website, $kind, $selector, $locale, $audience]
		);
	}//end cacheKey()


	/**
	 * Read the menus of a website.
	 *
	 * @param string $website  The website slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The menus, ordered by position.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	public function menus(string $website, string $locale, string $audience): array {
		$key = $this->cacheKey(website: $website, kind: 'menus', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->query(schema: 'menu', filters: ['website' => $website]);
		usort($rows, static fn ($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));

		$menus = array_map(fn (array $row) => $this->shapeMenu(row: $row), $rows);
		$this->cache->set($key, json_encode($menus), self::TTL);

		return $menus;
	}//end menus()


	/**
	 * Read the published pages of a website, without their bodies.
	 *
	 * @param string $website  The website slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The page summaries.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	public function pages(string $website, string $locale, string $audience): array {
		$key = $this->cacheKey(website: $website, kind: 'pages', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->query(schema: 'page', filters: ['website' => $website, 'status' => 'published']);
		$pages = [];
		foreach ($rows as $row) {
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
	 * @param string $website  The website slug.
	 * @param string $route    The in-site route.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array|null The page, or null when there is no published page there.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
	 */
	public function page(string $website, string $route, string $locale, string $audience): ?array {
		$key = $this->cacheKey(website: $website, kind: 'page', selector: $route, locale: $locale, audience: $audience);
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
		$rows = $this->query(schema: 'page', filters: ['website' => $website, 'route' => $route, 'status' => 'published']);
		$page = null;
		foreach ($rows as $row) {
			if ((string)($row['route'] ?? '') === $route) {
				$page = $this->shapePage(row: $row);
				break;
			}
		}

		$this->cache->set($key, json_encode($page ?? []), self::TTL);

		return $page;
	}//end page()


	/**
	 * Read the glossary of a website.
	 *
	 * @param string $website  The website slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array The glossary terms, alphabetical.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	public function glossary(string $website, string $locale, string $audience): array {
		$key = $this->cacheKey(website: $website, kind: 'glossary', selector: '', locale: $locale, audience: $audience);
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$rows = $this->query(schema: 'glossaryTerm', filters: ['website' => $website]);
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
	 * Drop every cached entry for a website.
	 *
	 * Invalidation is event-driven, not expiry-driven: an editor who publishes
	 * and then has to wait out a TTL will conclude the CMS is broken, and will
	 * be right.
	 *
	 * @param string $website The website slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function invalidate(string $website): void {
		// Prefix clear covers everything for this site, INCLUDING per-route
		// page entries, whose keys are not enumerable from here. That matters
		// more than it looks: the page cache stores negative results too, so a
		// missed invalidation leaves a newly created route 404ing for the rest
		// of the TTL while the object plainly exists.
		$this->cache->clear($website . '|');

		// Belt and braces for backends whose clear() ignores the prefix: the
		// keys that can be named are removed by name as well. Cheap, and the
		// alternative failure is invisible until someone reports stale content.
		foreach (['menus', 'pages', 'glossary'] as $kind) {
			foreach (['anonymous', 'authenticated'] as $audience) {
				foreach (['', 'nl', 'en'] as $locale) {
					$this->cache->remove($this->cacheKey(website: $website, kind: $kind, selector: '', locale: $locale, audience: $audience));
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
	 * Shape a stored page row for the API.
	 *
	 * @param array $row The stored page.
	 *
	 * @return array The API shape.
	 */
	private function shapePage(array $row): array {
		$body = (array)($row['body'] ?? []);
		$type = (string)($body['type'] ?? 'markdown');

		$shaped = [
			'title'   => (string)($row['title'] ?? ''),
			'route'   => (string)($row['route'] ?? ''),
			'summary' => (string)($row['summary'] ?? ''),
			'locale'  => (string)($row['locale'] ?? ''),
			'body'    => ['type' => $type],
		];

		if ($type === 'markdown') {
			// Served as SOURCE. Rendering to HTML here would force every
			// consumer that wants markdown — a Docusaurus build, most
			// obviously — to parse it back out, losing fidelity for nothing.
			$shaped['body']['markdown'] = (string)($body['markdown'] ?? '');
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

		return $shaped;
	}//end shapePage()


	/**
	 * Query one CMS schema with the given property filters.
	 *
	 * @param string $schema  The schema slug.
	 * @param array  $filters The property filters, always including `website`.
	 *
	 * @return array The rows, as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		if (($filters['website'] ?? '') === '') {
			// Refusing here rather than returning everything: an unscoped read
			// would silently serve one site's content under another's domain,
			// and the response would look entirely normal.
			$this->logger->error('Portaliq: refusing an unscoped CMS query', ['schema' => $schema]);
			return [];
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
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
