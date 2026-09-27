<?php

/**
 * Activity Feed Reader
 *
 * The guardian's read path for term-long activities
 * (extracurricular-activity-offer): every activity in the guardian's own
 * audience that is not a draft, with the places left and the guardian's own
 * children's sign-ups, never another guardian's. Also the one check every
 * guardian write runs first: is this activity in reach, and is this child the
 * guardian's own.
 *
 * The audience comes from the same interim `guardianAudienceFixture` seam the
 * news and event feeds use, matched by the same `NewsAudienceMatcher`.
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
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Reads activities for a guardian, scoped to their own audience and children.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * the one stateless audience predicate the news, event and activity feeds
 * share, so the rule cannot fork between them.
 */
class ActivityFeedReader {
	/**
	 * Constructor.
	 *
	 * @param ActivityStore $store The activity rows.
	 * @param GuardianAudienceFixtureReader $audienceReader The guardian's own audience.
	 * @param ActivityPlaces $places The places arithmetic.
	 */
	public function __construct(
		private readonly ActivityStore $store,
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly ActivityPlaces $places,
	) {
	}//end __construct()

	/**
	 * Every non-draft activity in the guardian's audience, each with
	 * `placesLeft` and `mySignups` (only the guardian's own children).
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function feedFor(string $subjectRef): array {
		if ($subjectRef === '') {
			return [];
		}

		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$signups = ($this->store->rows(schema: ActivityStore::SIGNUP) ?? []);

		$feed = [];
		foreach (($this->store->rows(schema: ActivityStore::OFFER) ?? []) as $activity) {
			if ($this->inReach(activity: $activity, audience: $audience) === false) {
				continue;
			}

			$own = $this->places->forActivity(signups: $signups, activityKeys: $this->store->keys(row: $activity));
			$activity['placesLeft'] = max(0, $this->places->placesFor(activity: $activity) - count($this->places->confirmed(signups: $own)));
			$activity['mySignups'] = $this->mySignups(signups: $own, childRefs: $audience['childRefs']);
			$feed[] = $activity;
		}

		return $feed;
	}//end feedFor()

	/**
	 * One activity the guardian may reach (not a draft, in their audience), by
	 * id or slug, or null. Every failure is the same null.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $activityId The activity id or slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function readOwnActivity(string $subjectRef, string $activityId): ?array {
		if ($subjectRef === '' || $activityId === '') {
			return null;
		}

		$activity = $this->store->find(schema: ActivityStore::OFFER, id: $activityId);
		if ($activity === null) {
			return null;
		}

		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		if ($this->inReach(activity: $activity, audience: $audience) === false) {
			return null;
		}

		return $activity;
	}//end readOwnActivity()

	/**
	 * Whether a child is one of the guardian's own children.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $childRef The child.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	public function isOwnChild(string $subjectRef, string $childRef): bool {
		if ($subjectRef === '' || $childRef === '') {
			return false;
		}

		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		return in_array($childRef, $audience['childRefs'], true);
	}//end isOwnChild()

	/**
	 * Whether a guardian may see an activity: not a draft, and its target
	 * meets their audience.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param array<string, mixed> $audience The guardian's resolved audience.
	 *
	 * @return bool
	 */
	private function inReach(array $activity, array $audience): bool {
		if (in_array(($activity['status'] ?? ''), ['open', 'closed'], true) === false) {
			return false;
		}

		$target = [];
		if (is_array($activity['target'] ?? null) === true) {
			$target = $activity['target'];
		}

		return NewsAudienceMatcher::matches(target: $target, audience: $audience);
	}//end inReach()

	/**
	 * The guardian's own children's sign-ups on one activity, withdrawn ones
	 * left out, with the waiting-list position where it applies.
	 *
	 * @param array<int, array<string, mixed>> $signups The activity's sign-ups.
	 * @param array<int, string> $childRefs The guardian's own children.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mySignups(array $signups, array $childRefs): array {
		$mine = [];
		foreach ($childRefs as $childRef) {
			$signup = $this->places->activeFor(signups: $signups, childRef: $childRef);
			if ($signup === null) {
				continue;
			}

			$entry = ['childRef' => $childRef, 'status' => (string)$signup['status']];
			if ($signup['status'] === ActivityPlaces::WAITLISTED) {
				$entry['position'] = $this->places->position(signups: $signups, childRef: $childRef);
			}

			if (is_string($signup['paymentRequestRef'] ?? null) === true && $signup['paymentRequestRef'] !== '') {
				$entry['paymentRequestRef'] = $signup['paymentRequestRef'];
			}

			$mine[] = $entry;
		}

		return $mine;
	}//end mySignups()
}//end class
