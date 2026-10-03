<?php

/**
 * Activity Roster
 *
 * The roster staff read for a term-long activity (extracurricular-activity-offer,
 * activity-parental-consent): the places, the children who hold one and the
 * waiting list in order, and, where photos are taken, whether each child's
 * signing guardian has photo consent on file.
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
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-where-photos-are-taken-the-roster-must-show-each-childs-photo-consent
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Builds the staff roster of one activity.
 *
 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-where-photos-are-taken-the-roster-must-show-each-childs-photo-consent
 */
class ActivityRoster {
	/**
	 * Constructor.
	 *
	 * @param ActivityStore $store The activity rows.
	 * @param ActivityPlaces $places The places arithmetic.
	 * @param ActivityFeedReader $feedReader Reads photo consent through the one consent seam.
	 */
	public function __construct(
		private readonly ActivityStore $store,
		private readonly ActivityPlaces $places,
		private readonly ActivityFeedReader $feedReader,
	) {
	}//end __construct()

	/**
	 * The roster staff read: the places, who holds one and the waiting list
	 * in order. Null when the activity is unknown or its sign-ups unreadable.
	 *
	 * @param string $activityId The activity id or slug.
	 *
	 * @return array{places: int, confirmed: array<int, array<string, mixed>>, waitlist: array<int, array<string, mixed>>}|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function roster(string $activityId): ?array {
		$activity = $this->store->lookup(schema: ActivityStore::OFFER, id: $activityId);
		if ($activity === null) {
			return null;
		}

		$signups = $this->signupsOf(activity: $activity);
		if ($signups === null) {
			return null;
		}

		return [
			'places' => $this->places->placesFor(activity: $activity),
			'confirmed' => $this->withPhotoConsent(activity: $activity, signups: $this->places->confirmed(signups: $signups)),
			'waitlist' => $this->withPhotoConsent(activity: $activity, signups: $this->places->waitlist(signups: $signups)),
		];
	}//end roster()

	/**
	 * Where photos are taken, mark each sign-up with whether the signing
	 * guardian has photo consent on file for the child. Read live, so a
	 * withdrawn consent shows at once; an absent entry is no consent.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param array<int, array<string, mixed>> $signups The sign-ups to mark.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-where-photos-are-taken-the-roster-must-show-each-childs-photo-consent
	 */
	private function withPhotoConsent(array $activity, array $signups): array {
		if (($activity['photosTaken'] ?? false) !== true) {
			return $signups;
		}

		foreach ($signups as $index => $signup) {
			$signups[$index]['photoConsent'] = $this->feedReader->photoConsentGranted(
				guardianRef: (string)($signup['guardianRef'] ?? ''),
				childRef: (string)($signup['childRef'] ?? '')
			);
		}

		return $signups;
	}//end withPhotoConsent()

	/**
	 * The activity's sign-ups, or null when they could not be read.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function signupsOf(array $activity): ?array {
		$rows = $this->store->rows(schema: ActivityStore::SIGNUP);
		if ($rows === null) {
			return null;
		}

		return $this->places->forActivity(signups: $rows, activityKeys: $this->store->keys(row: $activity));
	}//end signupsOf()
}//end class
