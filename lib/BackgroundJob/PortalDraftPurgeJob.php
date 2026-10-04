<?php

/**
 * Portaliq Portal Draft Purge Job (site-multi-step-forms, REQ-SMF-021)
 *
 * Retention is a promise the screen makes out loud ("Wij bewaren uw
 * antwoorden tot 1 november 2026"), so something has to keep it. Once a day
 * this deletes every draft whose own `expiresAt` has passed.
 *
 * The work is PortalDraftStore's; this class is the cron contract around it.
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\PortalDraftStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delete expired form drafts, once a day.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
 */
class PortalDraftPurgeJob extends TimedJob {
	/**
	 * Seconds between runs: a day.
	 */
	public const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Testable clock (job base class, and the moment measured against).
	 * @param PortalDraftStore $drafts Does the work.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalDraftStore $drafts,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		// A day late is still right, so this never takes a sensitive slot.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Delete the drafts whose retention has run out.
	 *
	 * @param mixed $argument The job argument; unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		try {
			$removed = $this->drafts->purgeExpired(now: gmdate('c', $this->time->getTime()));
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: the draft purge failed', ['reason' => $e->getMessage()]);
			return;
		}

		if ($removed > 0) {
			$this->logger->info('Portaliq: expired form drafts deleted', ['drafts' => $removed]);
		}
	}//end run()
}//end class
