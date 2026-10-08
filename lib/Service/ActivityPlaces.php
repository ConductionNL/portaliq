<?php

/**
 * Activity Places
 *
 * The arithmetic of an activity's places (extracurricular-activity-offer):
 * how many places supervision and capacity allow, which sign-ups belong to an
 * activity, who holds a place and who waits, in which order. Pure: no
 * storage, no clock, so the feed, the sign-up service and the attendance
 * service all count the same way.
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
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Counts places, holders and the waiting list of one activity.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
 */
class ActivityPlaces {
	/**
	 * A sign-up with a place.
	 */
	public const CONFIRMED = 'confirmed';

	/**
	 * A sign-up waiting for a place.
	 */
	public const WAITLISTED = 'waitlisted';

	/**
	 * A sign-up that no longer takes part.
	 */
	public const WITHDRAWN = 'withdrawn';

	/**
	 * The places an activity has: the lower of its capacity and its distinct
	 * supervisors times `childrenPerSupervisor`, or its capacity when no ratio
	 * is set. Never negative.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	public function placesFor(array $activity): int {
		$capacity = max(0, (int)($activity['capacity'] ?? 0));
		$ratio = max(0, (int)($activity['childrenPerSupervisor'] ?? 0));
		if ($ratio === 0) {
			return $capacity;
		}

		$supervisors = [];
		foreach ((array)($activity['supervisorRefs'] ?? []) as $ref) {
			if (is_string($ref) === true && $ref !== '') {
				$supervisors[$ref] = true;
			}
		}

		return min($capacity, count($supervisors) * $ratio);
	}//end placesFor()

	/**
	 * The sign-ups that point at this activity, by any name it answers to.
	 *
	 * @param array<int, array<string, mixed>> $signups Every sign-up row.
	 * @param array<int, string> $activityKeys The activity's id, uuid and slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function forActivity(array $signups, array $activityKeys): array {
		return array_values(
			array_filter(
				$signups,
				static fn (array $signup): bool => in_array((string)($signup['activityRef'] ?? ''), $activityKeys, true)
			)
		);
	}//end forActivity()

	/**
	 * The sign-ups holding a place.
	 *
	 * @param array<int, array<string, mixed>> $signups The activity's sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function confirmed(array $signups): array {
		return array_values(array_filter($signups, static fn (array $signup): bool => ($signup['status'] ?? '') === self::CONFIRMED));
	}//end confirmed()

	/**
	 * The waiting list, longest waiting first: by `signedUpAt`, then by id so
	 * two sign-ups in the same second keep a stable order.
	 *
	 * @param array<int, array<string, mixed>> $signups The activity's sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function waitlist(array $signups): array {
		$waiting = array_values(array_filter($signups, static fn (array $signup): bool => ($signup['status'] ?? '') === self::WAITLISTED));
		usort(
			$waiting,
			static function (array $left, array $right): int {
				$byTime = strcmp((string)($left['signedUpAt'] ?? ''), (string)($right['signedUpAt'] ?? ''));
				if ($byTime !== 0) {
					return $byTime;
				}

				return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
			}
		);

		return $waiting;
	}//end waitlist()

	/**
	 * A child's 1-based place in the waiting list, or null when not on it.
	 *
	 * @param array<int, array<string, mixed>> $signups The activity's sign-ups.
	 * @param string $childRef The child.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function position(array $signups, string $childRef): ?int {
		foreach ($this->waitlist(signups: $signups) as $index => $signup) {
			if ((string)($signup['childRef'] ?? '') === $childRef) {
				return ($index + 1);
			}
		}

		return null;
	}//end position()

	/**
	 * The child's sign-up that still counts (confirmed or waitlisted), or null.
	 *
	 * @param array<int, array<string, mixed>> $signups The activity's sign-ups.
	 * @param string $childRef The child.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function activeFor(array $signups, string $childRef): ?array {
		foreach ($signups as $signup) {
			if ((string)($signup['childRef'] ?? '') === $childRef
				&& in_array(($signup['status'] ?? ''), [self::CONFIRMED, self::WAITLISTED], true) === true
			) {
				return $signup;
			}
		}

		return null;
	}//end activeFor()
}//end class
