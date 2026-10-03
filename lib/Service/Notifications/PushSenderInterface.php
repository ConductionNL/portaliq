<?php

/**
 * Push Sender Interface
 *
 * Names the transport seam a real Web Push implementation (VAPID-signed
 * delivery to a browser's push service) would fill in later.
 * {@see LoggingPushSender} is the FIRST implementation — an honest interim
 * that logs the intended push rather than deliver it, since VAPID key
 * provisioning is an admin-settings concern out of scope for this change
 * (proposal.md Scope). Mirrors the `GuardianMessagingLeafInterface` pattern
 * the sibling `guardian-direct-messages` change already uses: design
 * against the interface, ship a minimal first implementation.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
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
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#messaging-leaf-interface-naming-convention-reused-from-guardian-direct-messages
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#messaging-leaf-interface-naming-convention-reused-from-guardian-direct-messages
 */
interface PushSenderInterface {
	/**
	 * Send a push notification to a subject.
	 *
	 * @param string $subjectRef The recipient's own subjectRef.
	 * @param string $title The notification title.
	 * @param string $body The notification body.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#messaging-leaf-interface-naming-convention-reused-from-guardian-direct-messages
	 */
	public function send(string $subjectRef, string $title, string $body): bool;
}//end interface
