<?php

/**
 * Portaliq Change Notice
 *
 * One change rule that fired for one record: the rule, the record, and the
 * field's new value.
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
 * A fired change rule PortalRecordChangeListener tells each recipient about. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
final class ChangeNotice {
	/**
	 * Constructor.
	 *
	 * @param array<string, string> $rule     The rule.
	 * @param string                $recordId The record's uuid.
	 * @param string                $value    The field's new value.
	 */
	public function __construct(
		public readonly array $rule,
		public readonly string $recordId,
		public readonly string $value,
	) {
	}//end __construct()
}//end class
