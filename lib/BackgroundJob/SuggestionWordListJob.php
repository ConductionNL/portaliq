<?php

/**
 * Portaliq suggestion word list job
 *
 * Rebuilds the search correction word list of every published portal once a
 * day.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
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
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily rebuild of every published portal's word list.
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SuggestionWordListJob extends TimedJob {

	/**
	 * Once a day.
	 */
	public const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time    The clock.
	 * @param PortalResolver     $portals The published portals.
	 * @param SuggestionWordList $list    Builds one portal's list.
	 * @param LoggerInterface    $logger  Logs a portal that failed.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalResolver $portals,
		private readonly SuggestionWordList $list,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Rebuild each published portal's list; one failure never stops another.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			try {
				$this->list->rebuild(portal: $slug);
			} catch (Throwable $e) {
				$this->logger->warning('[SuggestionWordListJob] Could not rebuild a word list: '.$e->getMessage(), ['portal' => $slug]);
			}
		}
	}//end run()
}//end class
