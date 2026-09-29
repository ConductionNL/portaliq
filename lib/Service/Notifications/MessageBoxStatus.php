<?php

/**
 * What integriq reports about a message box letter, in the words portaliq's
 * notification log uses, and a short memory for a report that arrives before
 * the log row exists.
 *
 * Integriq announces the first status of a letter inside the same dispatch
 * that sends it (DigitalPostService::handleSendRequest calls announce() before
 * it hands back the message id), so the status report reaches portaliq before
 * portaliq has written the row the report is about. The delivered listener
 * leaves that status here, and the sender takes it when it writes the row.
 *
 * A simulated letter is `simulated`, never `delivered`: integriq's own change
 * was written after a mock reported success for letters that never left the
 * instance (inbox-berichtenbox-channel, REQ-MBC-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

/**
 * Maps integriq's statuses and remembers an early report.
 *
 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 */
class MessageBoxStatus {

	/**
	 * The statuses a resident may see as a delivery.
	 *
	 * @var array<int, string>
	 */
	public const SHOWN = ['delivered', 'read'];

	/**
	 * Reports that arrived before their row, by integriq message id. Static,
	 * because the report and the send run in one PHP process but may be built
	 * by different containers.
	 *
	 * @var array<string, string>
	 */
	private static array $early = [];

	/**
	 * The notification log's status for an integriq status.
	 *
	 * @param string $status    Integriq's status: queued, sent, delivered, read or failed.
	 * @param bool   $simulated Whether integriq's binding sends nothing.
	 *
	 * @return string sent, failed, delivered, read or simulated.
	 *
	 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function fromIntegriq(string $status, bool $simulated): string {
		if ($status === 'failed') {
			return 'failed';
		}

		if ($simulated === true) {
			return 'simulated';
		}

		if (in_array($status, self::SHOWN, true) === true) {
			return $status;
		}

		return 'sent';
	}//end fromIntegriq()

	/**
	 * Keep a status whose row does not exist yet.
	 *
	 * @param string $messageId The integriq message id.
	 * @param string $status    The mapped status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function remember(string $messageId, string $status): void {
		// Bounded: a long cron run never grows this past a handful of letters.
		if (count(self::$early) >= 100) {
			self::$early = [];
		}

		self::$early[$messageId] = $status;
	}//end remember()

	/**
	 * The status reported early for this message, once, or null.
	 *
	 * @param string $messageId The integriq message id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function take(string $messageId): ?string {
		$status = (self::$early[$messageId] ?? null);
		unset(self::$early[$messageId]);

		return $status;
	}//end take()
}//end class
