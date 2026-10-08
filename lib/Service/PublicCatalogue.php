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
use OCA\Portaliq\Contribution\PublicDetailShape;
use OCA\Portaliq\Contribution\PublicIndexItems;
use OCA\Portaliq\Contribution\PublicIndexKinds;
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

	/**
	 * What each installed app declares its public index kinds can be narrowed
	 * by and drawn as. Not cached: it is read only when an editor opens the
	 * palette.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The kinds.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function kindsFor(string $portal): array {
		$out   = [];
		$shape = new PublicIndexKinds();
		foreach ($this->apps->getInstalledApps() as $appId) {
			$provider = $this->locator->locate(appId: (string)$appId);
			if ($provider === null || method_exists($provider, PublicIndexKinds::METHOD) === false) {
				continue;
			}

			try {
				$answer = $provider->{PublicIndexKinds::METHOD}($portal);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: public index kinds failed', ['app' => (string)$appId, 'reason' => $e->getMessage()]);
				continue;
			}

			$out = array_merge($out, $shape->kinds(appId: (string)$appId, answer: $answer));
		}

		return $out;
	}//end kindsFor()

	/**
	 * The filter values the signed-in person resolves to in one app: the
	 * value `visitor` of a table filter. An app that cannot say answers
	 * nothing, and the filter stays empty.
	 *
	 * @param string               $portal  The portal slug.
	 * @param string               $appId   The app whose index is read.
	 * @param array<string, mixed> $subject The signed-in subject.
	 *
	 * @return array<string, array<int, string>> Filter label to values.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
	 */
	public function visitorValuesFor(string $portal, string $appId, array $subject): array {
		if (in_array($appId, $this->apps->getInstalledApps(), true) === false) {
			return [];
		}

		$provider = $this->locator->locate(appId: $appId);
		if ($provider === null || method_exists($provider, PublicIndexKinds::VISITOR_METHOD) === false) {
			return [];
		}

		try {
			$answer = $provider->{PublicIndexKinds::VISITOR_METHOD}($portal, $subject);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: public index visitor failed', ['app' => $appId, 'reason' => $e->getMessage()]);
			return [];
		}

		return (new PublicIndexKinds())->visitorValues(answer: $answer);
	}//end visitorValuesFor()

	/**
	 * The page of one item of an app's public index, or null. The item must be
	 * one the app's index returns for this portal, of this kind, with this
	 * slug; anything else is the same null, so an unknown slug and an item that
	 * is not public cannot be told apart.
	 *
	 * @param string $portal The portal slug.
	 * @param string $appId  The app whose index holds the item.
	 * @param string $kind   The item's `type` in the index.
	 * @param string $slug   The item's slug.
	 *
	 * @return array{item: array<string, mixed>, detail: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-2
	 */
	public function detailFor(string $portal, string $appId, string $kind, string $slug): ?array {
		if ($slug === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $appId) !== 1) {
			return null;
		}

		$found = null;
		foreach ($this->itemsFor(portal: $portal) as $item) {
			if (str_starts_with((string)($item['id'] ?? ''), $appId . ':') === true
				&& ($item['type'] ?? '') === $kind
				&& ($item['slug'] ?? '') === $slug
			) {
				$found = $item;
				break;
			}
		}

		if ($found === null) {
			return null;
		}

		$provider = $this->locator->locate(appId: $appId);
		if ($provider === null || method_exists($provider, PublicDetailShape::METHOD) === false) {
			return null;
		}

		try {
			$answer = $provider->{PublicDetailShape::METHOD}($portal, substr((string)$found['id'], (strlen($appId) + 1)));
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: public detail failed', ['app' => $appId, 'reason' => $e->getMessage()]);
			return null;
		}

		$detail = (new PublicDetailShape())->detail(answer: $answer);
		if ($detail === null) {
			return null;
		}

		return ['item' => $found, 'detail' => $detail];
	}//end detailFor()
}//end class
