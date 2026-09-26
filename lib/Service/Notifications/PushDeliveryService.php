<?php

/**
 * Push Delivery Service
 *
 * The ONE call site any feature in this app uses to push a notification.
 * Checks {@see QuietHoursPolicy} for a non-emergency send and defers via
 * {@see PendingPushService} when the subject is currently quiet; an
 * emergency (noodmelding) send bypasses quiet hours unconditionally — the
 * one deliberate fail-OPEN in this app (design.md "Emergency bypass").
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
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */
class PushDeliveryService {
	/**
	 * Constructor.
	 *
	 * @param QuietHoursPolicy $quietHours The single source of truth for the quiet-hours decision.
	 * @param PushSenderInterface $sender The push transport.
	 * @param PendingPushService $pending The deferred-delivery queue.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly QuietHoursPolicy $quietHours,
		private readonly PushSenderInterface $sender,
		private readonly PendingPushService $pending,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Deliver a push, deferring a non-emergency send during quiet hours.
	 *
	 * @param string $subjectRef The recipient's own subjectRef.
	 * @param string $title The notification title.
	 * @param string $body The notification body.
	 * @param bool $emergency Bypasses quiet hours unconditionally when true (noodmelding).
	 *
	 * @return bool True when sent immediately OR successfully queued; false on failure.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `emergency` is the
	 * declared shape of the ONE deliberate fail-open branch in this app
	 * (design.md "Emergency bypass"), not an incidental mode switch; naming
	 * it out as two methods would hide that both share the identical
	 * fail-closed default for everything except this one flag.
	 */
	public function deliver(string $subjectRef, string $title, string $body, bool $emergency = false): bool {
		if ($subjectRef === '') {
			return false;
		}

		if ($emergency === true) {
			$this->logger->info('Portaliq: emergency push bypassing quiet hours', ['subjectRef' => $subjectRef]);
			return $this->sender->send(subjectRef: $subjectRef, title: $title, body: $body);
		}

		if ($this->quietHours->isQuietNow(subjectRef: $subjectRef) === true) {
			return $this->pending->queue(
				subjectRef: $subjectRef,
				title: $title,
				body: $body,
				deliverAfter: $this->quietHours->windowEnd(subjectRef: $subjectRef)
			);
		}

		return $this->sender->send(subjectRef: $subjectRef, title: $title, body: $body);
	}//end deliver()
}//end class
