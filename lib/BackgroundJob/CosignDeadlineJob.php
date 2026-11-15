<?php

/**
 * Portaliq Cosign Deadline Job
 *
 * Lapses the submissions whose co-sign deadline has passed, so they are never
 * delivered and the submitter's case says so.
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use DateTimeImmutable;
use OCA\Portaliq\Service\Intake\PortalCosignFlow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly: lapse the co-signatures past their deadline.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class CosignDeadlineJob extends TimedJob {

	/**
	 * Run once an hour.
	 *
	 * @var int
	 */
	private const INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time   The clock.
	 * @param PortalCosignFlow $cosign Lapses the co-signatures and tells each submitter.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalCosignFlow $cosign,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Lapse what is past its deadline.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	protected function run($argument): void {
		$this->cosign->lapse(now: (new DateTimeImmutable())->setTimestamp($this->time->getTime()));
	}//end run()
}//end class
