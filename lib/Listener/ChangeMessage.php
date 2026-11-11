<?php

/**
 * Portaliq Change Message
 *
 * One inbox message PortalRecordChangeListener writes: the record's title,
 * the record it is about, and the app's own words when it declares them.
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
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
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

/**
 * An inbox message PortalRecordChangeListener writes. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
final class ChangeMessage {
	/**
	 * Constructor.
	 *
	 * @param string                     $title      The record's title.
	 * @param array<string, string>      $recordLink The record the message is about.
	 * @param array<string, string>|null $text       The app's own words, or null for portaliq's.
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $recordLink,
		public readonly ?array $text,
	) {
	}//end __construct()
}//end class
