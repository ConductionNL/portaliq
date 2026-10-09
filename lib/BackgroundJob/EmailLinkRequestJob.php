<?php

/**
 * Portaliq Email Link Request Job
 *
 * One queued job for every accepted e-mail link request, known address or not
 * (security review M1), so the request answers in the same time either way.
 * Nextcloud removes a queued job before it runs it, so the address in the
 * argument is gone once the job has run.
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Hands one request to the sender.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 */
class EmailLinkRequestJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time   The clock.
	 * @param EmailLinkSender $sender Looks up and mails, or does nothing.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EmailLinkSender $sender,
	) {
		parent::__construct($time);
	}//end __construct()

	/**
	 * Run one request.
	 *
	 * @param mixed $argument `{portal, address, cookieHash}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-reveals-nothing-about-accounts-req-iwi-007
	 */
	protected function run($argument): void {
		$argument = (array)$argument;
		$this->sender->handle(
			portalSlug: (string)($argument['portal'] ?? ''),
			address: (string)($argument['address'] ?? ''),
			cookieHash: (string)($argument['cookieHash'] ?? '')
		);
	}//end run()
}//end class
