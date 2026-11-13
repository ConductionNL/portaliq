<?php

/**
 * Portaliq suggestion word list job
 *
 * Daily, rebuilds every published portal's did-you-mean word list from the
 * anonymous public search.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Keeps the did-you-mean word lists current.
 */
class SuggestionWordListJob extends TimedJob {
	/**
	 * Once a day.
	 */
	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time    The clock.
	 * @param PortalResolver     $portals The published portals.
	 * @param SuggestionWordList $words   The word list.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalResolver $portals,
		private readonly SuggestionWordList $words,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Rebuild the list of every published portal.
	 *
	 * The anonymous search is read once and stored per portal.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	protected function run($argument): void {
		$this->rebuildAll();
	}//end run()

	/**
	 * Rebuild the list of every published portal.
	 *
	 * @return int How many portals got a list.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function rebuildAll(): int {
		$slugs = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === false || is_string($portal['slug'] ?? null) === false || $portal['slug'] === '') {
				continue;
			}

			$slugs[$portal['slug']] = true;
		}

		if ($slugs === []) {
			return 0;
		}

		$words = $this->words->build();
		foreach (array_keys($slugs) as $slug) {
			$this->words->store(portal: (string)$slug, words: $words);
		}

		return count($slugs);
	}//end rebuildAll()
}//end class
