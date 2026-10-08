<?php

/**
 * Records what integriq reports about a letter portaliq asked it to send.
 *
 * Integriq dispatches DigitalPostDeliveredEvent on every status change of a
 * digital post message. This listener takes only the letters whose
 * `requestedBy` is portaliq, finds the `portalNotification` row by integriq's
 * message id and writes the new status onto it (inbox-berichtenbox-channel,
 * REQ-MBC-004). A simulated report is `simulated`, never `delivered`.
 *
 * Integriq announces the first status inside the send itself, before portaliq
 * has written the row; that report is kept in MessageBoxStatus for the sender.
 *
 * 🔴 IT NEVER FAILS INTEGRIQ'S WORK. Every failure is caught and logged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 *
 * @template-implements IEventListener<Event>
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCA\Portaliq\Service\Notifications\MessageBoxChannel;
use OCA\Portaliq\Service\Notifications\MessageBoxSender;
use OCA\Portaliq\Service\Notifications\MessageBoxStatus;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes integriq's status onto the message box row.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 *
 * @template-implements IEventListener<Event>
 */
class PortalDigitalPostDeliveredListener implements IEventListener {

	/**
	 * Integriq's status report, named as a string so portaliq does not depend
	 * on integriq being installed.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Integriq\\Event\\DigitalPostDeliveredEvent';

	/**
	 * Wire the listener.
	 *
	 * @param PortalObjectReader $reader Finds the row by integriq's message id.
	 * @param PortalObjectWriter $writer Writes the status.
	 * @param LoggerInterface    $logger The logger.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle integriq's report.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function handle(Event $event): void {
		// Without integriq the class does not exist and nothing is an instance of it.
		if (($event instanceof DigitalPostDeliveredEvent) === false) {
			return;
		}

		if ($event->getRequestedBy() !== MessageBoxSender::REQUESTED_BY || $event->getMessageId() === '') {
			return;
		}

		try {
			$this->record(
				messageId: $event->getMessageId(),
				status: (new MessageBoxStatus())->fromIntegriq(status: $event->getStatus(), simulated: $event->isSimulated())
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: message box status was not recorded', ['exception' => get_class($e)]);
		}
	}//end handle()

	/**
	 * Write the status onto the row, or keep it for the send still writing it.
	 *
	 * @param string $messageId Integriq's message id.
	 * @param string $status    The mapped status.
	 *
	 * @return void
	 */
	private function record(string $messageId, string $status): void {
		$row = null;
		$rows = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portalNotification',
			scopeField: 'externalMessageId',
			subjectRef: $messageId,
			organisation: '',
			limit: 2
		);
		foreach ($rows as $candidate) {
			if (($candidate['externalMessageId'] ?? null) === $messageId && ($candidate['channel'] ?? null) === MessageBoxChannel::CHANNEL) {
				$row = $candidate;
				break;
			}
		}

		if ($row === null) {
			(new MessageBoxStatus())->remember(messageId: $messageId, status: $status);
			return;
		}

		$rowId = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
		if ($rowId === '' || ($row['status'] ?? null) === $status) {
			return;
		}

		$updated = $this->writer->updateObject(
			register: 'portaliq',
			schema: 'portalNotification',
			scopeField: 'externalMessageId',
			subjectRef: $messageId,
			organisation: (string)($row['organisation'] ?? ''),
			id: $rowId,
			data: ['status' => $status, 'lastAttemptAt' => gmdate('c')]
		);
		if ($updated === null) {
			$this->logger->warning('Portaliq: message box status was not written', ['status' => $status]);
		}
	}//end record()
}//end class
