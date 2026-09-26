<?php

/**
 * Logging Push Sender
 *
 * The FIRST implementation of {@see PushSenderInterface} — logs the
 * intended push rather than deliver it over a real Web Push transport,
 * which needs VAPID key provisioning (an admin-settings concern out of
 * scope for this change). Swappable in DI
 * (`lib/AppInfo/Application.php::registerServiceAlias`) for a real
 * transport later with no caller change.
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

use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#messaging-leaf-interface-naming-convention-reused-from-guardian-direct-messages
 */
class LoggingPushSender implements PushSenderInterface {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $subjectRef The recipient's own subjectRef.
	 * @param string $title The notification title.
	 * @param string $body The notification body.
	 *
	 * @return bool Always true — logging never fails to "send".
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#messaging-leaf-interface-naming-convention-reused-from-guardian-direct-messages
	 */
	public function send(string $subjectRef, string $title, string $body): bool {
		$this->logger->info('Portaliq: push (interim logging transport)', [
			'subjectRef' => $subjectRef,
			'title' => $title,
		]);

		return true;
	}//end send()
}//end class
