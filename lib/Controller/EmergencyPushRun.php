<?php

/**
 * Portaliq Emergency Push Run
 *
 * One emergency push being delivered: what it says and how far it reached.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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

namespace OCA\Portaliq\Controller;

/**
 * The push of one EmergencyPushController::send() call. Never leaves that controller.
 *
 * @internal
 *
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */
final class EmergencyPushRun {
	/**
	 * How many guardians the target reaches.
	 *
	 * @var integer
	 */
	public int $recipientCount = 0;

	/**
	 * How many of those a push really reached.
	 *
	 * @var integer
	 */
	public int $deliveredCount = 0;

	/**
	 * Constructor.
	 *
	 * @param string $title The notification title.
	 * @param string $body  The notification body.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $body,
	) {
	}//end __construct()
}//end class
