<?php

/**
 * Portaliq Portal Draft Purge Job
 *
 * Deletes the saved drafts whose retention has passed, so portaliq holds a
 * resident's half-filled answers no longer than the action declared.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Intake\PortalDraftStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly purge of expired drafts.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */
class PortalDraftPurgeJob extends TimedJob {
	/**
	 * How often the purge runs, in seconds.
	 */
	private const INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The scheduler's clock.
	 * @param PortalDraftStore $drafts The draft store.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalDraftStore $drafts,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Delete the expired drafts.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		$this->drafts->purgeExpired();
	}//end run()
}//end class
