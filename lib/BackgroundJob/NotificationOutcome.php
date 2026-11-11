<?php

/**
 * Portaliq Notification Outcome
 *
 * How one dispatch attempt on one channel ended, as the append-only
 * `portalNotification` log records it.
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
 * @spec openspec/specs/supplier-portal/spec.md#every-dispatch-attempt-is-logged
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

/**
 * The status, failure streak and channel of one attempt.
 *
 * @spec openspec/specs/supplier-portal/spec.md#every-dispatch-attempt-is-logged
 */
final class NotificationOutcome {
	/**
	 * Constructor.
	 *
	 * @param string $status   `sent` or `failed`.
	 * @param int    $attempts The consecutive-failure counter as of this attempt.
	 * @param string $channel  `email` or `push`.
	 */
	public function __construct(
		public readonly string $status,
		public readonly int $attempts,
		public readonly string $channel = 'email',
	) {
	}//end __construct()

	/**
	 * Whether the attempt went through.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
	 */
	public function isSent(): bool {
		return $this->status === 'sent';
	}//end isSent()
}//end class
