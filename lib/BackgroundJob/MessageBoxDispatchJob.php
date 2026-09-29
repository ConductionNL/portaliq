<?php

/**
 * Sends one inbox message to the resident's government message box.
 *
 * Queued by MessageBoxChannel when a new message lands in an inbox collection
 * that names a recipient method, in an organisation that offers the channel,
 * for a resident who did not switch it off. The argument names the message and
 * the method, never a recipient (inbox-berichtenbox-channel, REQ-MBC-003).
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
 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Notifications\MessageBoxSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one message box send.
 *
 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
 */
class MessageBoxDispatchJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory     $time   The clock.
	 * @param MessageBoxSender $sender Sends and logs.
	 * @param LoggerInterface  $logger The logger; never given the recipient.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MessageBoxSender $sender,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the send. Never throws: the cron runner is never disrupted.
	 *
	 * @param mixed $argument The argument MessageBoxChannel queued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		try {
			$this->sender->send(argument: $argument);
		} catch (Throwable $e) {
			// The exception's message may quote the recipient: log its class only.
			$this->logger->error('Portaliq: MessageBoxDispatchJob failed', ['exception' => get_class($e)]);
		}
	}//end run()
}//end class
