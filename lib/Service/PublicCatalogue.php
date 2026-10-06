<?php

/**
 * Portaliq Public Catalogue (portal-public-catalogue)
 *
 * What a visitor can find on a portal without signing in: the portal's own
 * public news, and every installed app's public index for that portal
 * (PublicIndexItems), over items the apps already decided are public;
 * portaliq reads no object of an app itself, so nothing an app keeps private
 * can surface through it. PublicCatalogueQuery searches, filters and pages them.
 *
 * The gathered items are kept for five minutes per portal in the distributed
 * cache; a visitor's query never reaches an app.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicIndexItems;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gathers a portal's public catalogue.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */
class PublicCatalogue {
	/**
	 * The type of the portal's own news items.
	 */
	public const TYPE_NEWS = 'news';

	/**
	 * How long the gathered items are kept, in seconds.
	 */
	private const TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param IAppManager           $apps    The installed apps.
	 * @param PortalProviderLocator $locator Finds each app's provider.
	 * @param PublicNewsReader      $news    The portal's own public news.
	 * @param ICacheFactory         $caches  The distributed cache.
	 * @param LoggerInterface       $logger  Logs an app whose index failed.
	 */
	public function __construct(
		private readonly IAppManager $apps,
		private readonly PortalProviderLocator $locator,
		private readonly PublicNewsReader $news,
		private readonly ICacheFactory $caches,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every public item of one portal: its news, then each app's index.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function itemsFor(string $portal): array {
		$cache = null;
		try {
			$cache  = $this->caches->createDistributed('portaliq-catalogue');
			$cached = $cache->get(md5($portal));
			if (is_array($cached) === true) {
				return $cached;
			}
		} catch (Throwable $e) {
			$cache = null;
		}

		$items = array_merge($this->newsItems(portal: $portal), $this->appItems(portal: $portal));
		try {
			$cache?->set(md5($portal), $items, self::TTL);
		} catch (Throwable $e) {
			// A cache that cannot hold the items only costs the next visitor a read.
			$this->logger->debug('Portaliq: catalogue not cached', ['reason' => $e->getMessage()]);
		}

		return $items;
	}//end itemsFor()

	/**
	 * The portal's public news as catalogue items.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function newsItems(string $portal): array {
		$out = [];
		foreach ($this->news->allFor(portal: $portal) as $row) {
			$id    = (string)($row['id'] ?? '');
			$title = trim((string)($row['title'] ?? ''));
			if ($id === '' || $title === '') {
				continue;
			}

			$item = ['id' => 'news:' . $id, 'newsId' => $id, 'type' => self::TYPE_NEWS, 'kind' => '', 'title' => $title];
			if (trim((string)($row['intro'] ?? '')) !== '') {
				$item['summary'] = trim((string)$row['intro']);
			}

			if ((string)($row['publishedAt'] ?? '') !== '') {
				$item['date'] = (string)$row['publishedAt'];
			}

			if (trim((string)($row['audienceLabel'] ?? '')) !== '') {
				$item['meta'] = [trim((string)$row['audienceLabel'])];
			}

			$out[] = $item;
		}//end foreach

		return $out;
	}//end newsItems()

	/**
	 * Every installed app's public index for the portal, each answer held to
	 * its shape. An app that fails adds nothing and the rest still count.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function appItems(string $portal): array {
		$out   = [];
		$shape = new PublicIndexItems();
		foreach ($this->apps->getInstalledApps() as $appId) {
			$provider = $this->locator->locate(appId: (string)$appId);
			if ($provider === null || method_exists($provider, PublicIndexItems::METHOD) === false) {
				continue;
			}

			try {
				$answer = $provider->{PublicIndexItems::METHOD}($portal);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: public index failed', ['app' => (string)$appId, 'reason' => $e->getMessage()]);
				continue;
			}

			$out = array_merge($out, $shape->items(appId: (string)$appId, entries: $answer));
		}

		return $out;
	}//end appItems()

}//end class
