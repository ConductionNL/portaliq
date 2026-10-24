<?php

/**
 * Portaliq CMS Content Cache (portaliq-cms)
 *
 * The audience-keyed cache of CMS content reads, with hit and miss counts.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\ICache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The distributed cache the CMS reads go through.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */
class CmsContentCache {
	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private const TTL = 300;

	/**
	 * The prefix of the hit and miss counters.
	 *
	 * @var string
	 */
	private const STATS_PREFIX = '__stats|';

	/**
	 * Constructor.
	 *
	 * @param ICache          $cache  The distributed cache.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ICache $cache,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read one cache entry and count whether it was there.
	 *
	 * The counts are what tells a cache that never hits from no cache at all.
	 *
	 * @param string $key The cache key.
	 *
	 * @return mixed The stored value, or null on a miss.
	 *
	 * @spec openspec/changes/portal-headless-content-api/tasks.md#task-2
	 */
	public function lookup(string $key): mixed {
		$hit = $this->cache->get($key);
		$outcome = 'hits';
		if ($hit === null) {
			$outcome = 'misses';
		}

		$this->count(outcome: $outcome);

		return $hit;
	}//end lookup()

	/**
	 * Add one to a cache outcome counter. A lost increment under a race is acceptable for a gauge.
	 *
	 * @param string $outcome `hits` or `misses`.
	 *
	 * @return void
	 */
	private function count(string $outcome): void {
		try {
			$this->cache->set(self::STATS_PREFIX . $outcome, ((int)$this->cache->get(self::STATS_PREFIX . $outcome)) + 1, 0);
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: content cache counter failed', ['reason' => $e->getMessage()]);
		}
	}//end count()

	/**
	 * How many content reads the cache answered and how many it missed.
	 *
	 * @return array{hits: int, misses: int}
	 *
	 * @spec openspec/changes/portal-headless-content-api/tasks.md#task-2
	 */
	public function stats(): array {
		return [
			'hits'   => (int)$this->cache->get(self::STATS_PREFIX . 'hits'),
			'misses' => (int)$this->cache->get(self::STATS_PREFIX . 'misses'),
		];
	}//end stats()

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
	public function key(string $portal, string $kind, string $selector, string $locale, string $audience): string {
		// `audience` is NOT optional and NOT last-by-accident. Dropping it is
		// the single change that turns this cache into a cross-visitor data
		// leak, so it is part of the key's identity, not a suffix.
		return implode(
			'|',
			[$portal, $kind, $selector, $locale, $audience]
		);
	}//end key()

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
		foreach (['menus', 'pages', 'glossary', 'faq'] as $kind) {
			foreach (['anonymous', 'authenticated'] as $audience) {
				foreach (['', 'nl', 'en'] as $locale) {
					$this->cache->remove($this->key(portal: $portal, kind: $kind, selector: '', locale: $locale, audience: $audience));
				}
			}
		}
	}//end invalidate()

	/**
	 * Store a value under a key for the cache lifetime.
	 *
	 * @param string $key   The cache key.
	 * @param string $value The encoded value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function store(string $key, string $value): void {
		$this->cache->set($key, $value, self::TTL);
	}//end store()
}//end class
