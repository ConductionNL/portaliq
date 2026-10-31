<?php

/**
 * Event RSVP Service
 *
 * Upserts a guardian's RSVP for one child on one event: a second RSVP for
 * the same guardian+child+event UPDATES the existing record rather than
 * creating a second one. The generic contribution-contract writer's plain
 * merge-update cannot express "find the existing row by a compound key,
 * else create" — hence this dedicated service, the RSVP counterpart to the
 * sibling change's `NewsReadReceiptService`.
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
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The seats an answer to an event records.
 *
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
class EventSeatRules {
	/**
	 * The seats one answer may ask for when the event names no maximum.
	 */
	private const DEFAULT_MAX_SEATS = 4;

	/**
	 * The seats to record: null when the event does not ask for seats, 0 for
	 * an answer other than yes, else the asked number; false when the number
	 * is outside 1 to the event's maximum.
	 *
	 * @param array<string, mixed> $event    The event.
	 * @param string               $response The response.
	 * @param int|null             $seats    The seats asked.
	 *
	 * @return int|false|null
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	public function seatsToRecord(array $event, string $response, ?int $seats): int|false|null {
		if (($event['askSeats'] ?? false) !== true) {
			return null;
		}

		if ($response !== 'yes') {
			return 0;
		}

		$max = (int)($event['maxSeatsPerAnswer'] ?? self::DEFAULT_MAX_SEATS);
		if ($max < 1) {
			$max = self::DEFAULT_MAX_SEATS;
		}

		if ($seats === null || $seats < 1 || $seats > $max) {
			return false;
		}

		return $seats;
	}//end seatsToRecord()
}//end class
