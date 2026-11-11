<?php

/**
 * Portaliq Notification Trigger
 *
 * One trigger for an out-of-band notification: the rule key, the app whose
 * manifest is checked, the subject, and the record it is about.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * What one NotificationDispatchService::dispatch() call is about.
 *
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 */
final class NotificationTrigger {
	/**
	 * Constructor.
	 *
	 * @param string                $ruleKey The trigger's rule key.
	 * @param string                $appId   The contributing app whose manifest is checked.
	 * @param array<string, mixed>  $subject The resolved subject.
	 * @param array<string, string> $record  The record the trigger is about, or [].
	 */
	public function __construct(
		public readonly string $ruleKey,
		public readonly string $appId,
		public readonly array $subject,
		public readonly array $record,
	) {
	}//end __construct()
}//end class
