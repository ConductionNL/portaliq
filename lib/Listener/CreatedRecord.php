<?php

/**
 * Portaliq Created Record
 *
 * One record creation PortalRecordChangeListener nudges inboxes for: the
 * record, its uuid, and which app-and-resident pairs were already told.
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
 * The state of one PortalRecordChangeListener creation. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
final class CreatedRecord {
	/**
	 * Which app-and-resident pairs were already told, so one message is one
	 * nudge however many audiences list the inbox.
	 *
	 * @var array<string, true>
	 */
	public array $told = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data     The created record.
	 * @param string               $recordId The record's uuid.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $recordId,
	) {
	}//end __construct()
}//end class
