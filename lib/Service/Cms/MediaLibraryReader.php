<?php

/**
 * Portaliq media library reader
 *
 * Reads a portal's published media items, and the published pages that use
 * one.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The media library of ONE portal, read the way CmsReader reads content.
 *
 * Status and portal are filtered in the QUERY: a draft item never reaches
 * this process, so it answers exactly like an unknown id. The items are cached
 * in CmsReader's cache under the portal's prefix, so CmsReader::invalidate()
 * on a media write drops them with the rest of the portal's content.
 */
class MediaLibraryReader {

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const TTL = 300;

	/**
	 * The shared CMS cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container    For the lazy OpenRegister lookup.
	 * @param ICacheFactory         $cacheFactory Creates the distributed cache CmsReader uses.
	 * @param LoggerInterface       $logger       The logger.
	 * @param PortalRegisterContext $context      Points the shared ObjectService at this app's schemas.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_cms');
	}//end __construct()

	/**
	 * The published media items of a portal, by id.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, array{id: string, title: string, alt: string, kind: string}>
	 *
	 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
	 */
	public function items(string $portal): array {
		$key = $portal.'|media|||anonymous';
		$hit = $this->cache->get($key);
		if ($hit !== null) {
			return json_decode($hit, true) ?? [];
		}

		$items = [];
		foreach ($this->query(schema: 'media', filters: ['portal' => $portal, 'status' => 'published']) as $row) {
			$id = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
			if ($id === '' || (string)($row['portal'] ?? '') !== $portal || ($row['status'] ?? '') !== 'published') {
				continue;
			}

			$items[$id] = [
				'id'    => $id,
				'title' => (string)($row['title'] ?? ''),
				'alt'   => (string)($row['alt'] ?? ''),
				'kind'  => (string)($row['kind'] ?? 'file'),
			];
		}

		$this->cache->set($key, json_encode($items), self::TTL);

		return $items;
	}//end items()

	/**
	 * One published media item of a portal, or null.
	 *
	 * @param string $portal The portal slug.
	 * @param string $id     The item id.
	 *
	 * @return array{id: string, title: string, alt: string, kind: string}|null
	 *
	 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
	 */
	public function item(string $portal, string $id): ?array {
		if ($portal === '' || $id === '') {
			return null;
		}

		return ($this->items(portal: $portal)[$id] ?? null);
	}//end item()

	/**
	 * The routes of the published pages of a portal that use an item, as hero
	 * image, share image or anywhere in the body.
	 *
	 * Uncached: it guards a delete, and a stale answer there removes an image
	 * from a live page.
	 *
	 * @param string $portal The portal slug.
	 * @param string $id     The item id.
	 *
	 * @return list<string> The routes, sorted.
	 *
	 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
	 */
	public function pagesUsing(string $portal, string $id): array {
		if ($portal === '' || $id === '') {
			return [];
		}

		$pattern = '/'.preg_quote(MediaReferences::PREFIX.$id, '/').'(?![A-Za-z0-9-])/';
		$routes  = [];
		foreach ($this->query(schema: 'page', filters: ['portal' => $portal, 'status' => 'published']) as $row) {
			$used = json_encode([$row['heroImage'] ?? null, $row['seoImage'] ?? null, $row['body'] ?? null]);
			if (is_string($used) === true && preg_match($pattern, $used) === 1) {
				$routes[] = (string)($row['route'] ?? '');
			}
		}

		sort($routes);

		return $routes;
	}//end pagesUsing()

	/**
	 * Query one of this app's schemas, scoped to a portal, RBAC off (the
	 * filters are the scope).
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters, always including `portal`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function query(string $schema, array $filters): array {
		try {
			$objects = $this->container->get(self::OBJECT_SERVICE);
			if ($this->context->apply(objectService: $objects, schemaSlug: $schema) === false) {
				return [];
			}

			$rows = $objects->findAll(
				config: ['filters' => $filters, 'limit' => 500, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: media library read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = (array)$row->jsonSerialize();
			}

			$out[] = $row;
		}

		return $out;
	}//end query()
}//end class
