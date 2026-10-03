<?php

/**
 * Portaliq Traffic Day Guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Tells a day whose raw events are all still there from one that lost
 * some to the purge. Pure.
 *
 * Raw events expire a retention period after they were RECEIVED, and the
 * purge runs every fifteen minutes, so the oldest retained day can be
 * half gone. Rebuilding it would trade a complete record for a partial
 * one. The back-fill asks here first.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */
class TrafficDayGuard {

	/**
	 * Whether a rebuilt record counts less than the stored one: fewer page
	 * views or fewer events.
	 *
	 * @param array<string, mixed>      $record The rebuilt "all sessions" record.
	 * @param array<string, mixed>|null $stored The stored one, or null.
	 *
	 * @return bool True when the rebuild lost events.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	public function lostEvents(array $record, ?array $stored): bool {
		if ($stored === null) {
			return false;
		}

		if ((int)($record['pageViews'] ?? 0) < (int)($stored['pageViews'] ?? 0)) {
			return true;
		}

		return $this->eventTotal(events: ($record['events'] ?? [])) < $this->eventTotal(events: ($stored['events'] ?? []));
	}

	/**
	 * The sum of a record's event-name counts.
	 *
	 * @param mixed $events The `events` map.
	 *
	 * @return int The total.
	 */
	private function eventTotal(mixed $events): int {
		if (is_array($events) === false) {
			return 0;
		}

		return (int)array_sum(array_map('intval', $events));
	}
}
