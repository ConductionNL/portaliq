<?php

/**
 * Portaliq Record Change
 *
 * One record update PortalRecordChangeListener reports: the record before
 * and after, its uuid and its organisation.
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
 * The state of one PortalRecordChangeListener update. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
final class RecordChange {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $newData      The record after the change.
	 * @param array<string, mixed> $oldData      The record before the change.
	 * @param string               $recordId     The record's uuid.
	 * @param string               $organisation The record's organisation uuid.
	 */
	public function __construct(
		public readonly array $newData,
		public readonly array $oldData,
		public readonly string $recordId,
		public readonly string $organisation,
	) {
	}//end __construct()
}//end class
