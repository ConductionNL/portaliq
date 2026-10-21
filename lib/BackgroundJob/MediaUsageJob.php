<?php

/**
 * Portaliq media usage job
 *
 * Hourly, recounts how many records of its portal refer to each media item.
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
 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Cms\MediaUsage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Keeps the media library's usage counts current.
 *
 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
 */
class MediaUsageJob extends TimedJob {
	/**
	 * Once an hour.
	 */
	private const INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param MediaUsage $usage Recounts the library.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MediaUsage $usage,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Recount.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; this job takes no argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
	 */
	protected function run($argument): void {
		$this->usage->refresh();
	}//end run()
}//end class
