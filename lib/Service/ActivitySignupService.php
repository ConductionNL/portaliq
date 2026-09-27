<?php

/**
 * Activity Signup Service
 *
 * Places, the waiting list and promotion for term-long activities
 * (extracurricular-activity-offer). A guardian signs up one of their own
 * children: the child gets a place while the activity has one, a spot on the
 * waiting list when it is full and keeps a list, and a refusal otherwise. A
 * place that frees up, by a withdrawal or by more supervisors, goes to the
 * child who waited longest.
 *
 * Every refusal happens before anything is written. The count runs right
 * before the write; OpenRegister has no conditional write, so two sign-ups
 * for the last place in the same instant can both pass (design.md, Risk 1).
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
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Signs children up, withdraws them and promotes from the waiting list.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
 */
class ActivitySignupService {
	/**
	 * Refusal: the activity is out of reach, or the child is not the guardian's (404).
	 */
	public const REASON_NOT_FOUND = 'not_found';

	/**
	 * Refusal: the activity is closed or its deadline passed (422).
	 */
	public const REASON_CLOSED = 'signup_closed';

	/**
	 * Refusal: no place and no waiting list (422).
	 */
	public const REASON_FULL = 'activity_full';

	/**
	 * Refusal: the child already has a sign-up that counts (409).
	 */
	public const REASON_DUPLICATE = 'already_signed_up';

	/**
	 * Refusal: the sign-ups could not be read or written, so no place can be promised (502).
	 */
	public const REASON_UNAVAILABLE = 'activity_unavailable';

	/**
	 * Refusal: the activity needs consent and the current consent text was not accepted (422).
	 */
	public const REASON_CONSENT_REQUIRED = 'consent_required';

	/**
	 * Constructor.
	 *
	 * @param ActivityStore $store The activity rows.
	 * @param ActivityFeedReader $feedReader Reach and own-child checks.
	 * @param ActivityPlaces $places The places arithmetic.
	 * @param ITimeFactory $time The clock for deadlines and timestamps.
	 */
	public function __construct(
		private readonly ActivityStore $store,
		private readonly ActivityFeedReader $feedReader,
		private readonly ActivityPlaces $places,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Sign one of the guardian's own children up.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $activityId The activity id or slug.
	 * @param string $childRef The child.
	 * @param string $note An optional note for the supervisor.
	 * @param string $acceptedStatement The consent text the guardian agreed to; must equal the
	 *                                  activity's current text when it needs consent.
	 *
	 * @return array{status?: string, position?: int|null, error?: string}
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-an-activity-must-be-able-to-require-a-guardians-consent-recorded-on-the-sign-up
	 */
	public function signUp(string $subjectRef, string $activityId, string $childRef, string $note = '', string $acceptedStatement = ''): array {
		$checked = $this->checkedSignup(subjectRef: $subjectRef, activityId: $activityId, childRef: $childRef, acceptedStatement: $acceptedStatement);
		if (is_string($checked) === true) {
			return ['error' => $checked];
		}

		$status = $checked['status'];
		$saved = $this->writeSignup(activity: $checked['activity'], subjectRef: $subjectRef, childRef: $childRef, note: $note, status: $status);
		if ($saved === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		if ($status === ActivityPlaces::CONFIRMED) {
			return ['status' => $status];
		}

		$signups = $checked['signups'];
		$signups[] = $saved;
		return ['status' => $status, 'position' => $this->places->position(signups: $signups, childRef: $childRef)];
	}//end signUp()

	/**
	 * Every check a sign-up must pass, in order, before anything is written:
	 * reach and own child, open and before the deadline, consent accepted when
	 * needed, sign-ups readable, no sign-up that still counts, room or a
	 * waiting list.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $activityId The activity id or slug.
	 * @param string $childRef The child.
	 * @param string $acceptedStatement The consent text the guardian agreed to.
	 *
	 * @return array{activity: array<string, mixed>, signups: array<int, array<string, mixed>>, status: string}|string
	 *         The context, or a REASON_* constant.
	 */
	private function checkedSignup(string $subjectRef, string $activityId, string $childRef, string $acceptedStatement): array|string {
		$activity = $this->feedReader->readOwnActivity(subjectRef: $subjectRef, activityId: $activityId);
		if ($activity === null || $this->feedReader->isOwnChild(subjectRef: $subjectRef, childRef: $childRef) === false) {
			return self::REASON_NOT_FOUND;
		}

		if ($this->acceptsSignups(activity: $activity) === false) {
			return self::REASON_CLOSED;
		}

		if ($this->consentAccepted(activity: $activity, acceptedStatement: $acceptedStatement) === false) {
			return self::REASON_CONSENT_REQUIRED;
		}

		$signups = $this->signupsOf(activity: $activity);
		if ($signups === null) {
			return self::REASON_UNAVAILABLE;
		}

		if ($this->places->activeFor(signups: $signups, childRef: $childRef) !== null) {
			return self::REASON_DUPLICATE;
		}

		$status = $this->statusForNewSignup(activity: $activity, signups: $signups);
		if ($status === null) {
			return self::REASON_FULL;
		}

		return ['activity' => $activity, 'signups' => $signups, 'status' => $status];
	}//end checkedSignup()

	/**
	 * Write a new sign-up with the status the checks decided.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $childRef The child.
	 * @param string $note The note for the supervisor.
	 * @param string $status Confirmed or waitlisted.
	 *
	 * @return array<string, mixed>|null The saved row, or null on failure.
	 */
	private function writeSignup(array $activity, string $subjectRef, string $childRef, string $note, string $status): ?array {
		$now = $this->now();
		$row = [
			'activityRef' => $this->store->idOf(row: $activity),
			'childRef' => $childRef,
			'guardianRef' => $subjectRef,
			'status' => $status,
			'note' => $note,
			'signedUpAt' => $now,
		];
		if ($status === ActivityPlaces::CONFIRMED) {
			$row['confirmedAt'] = $now;
		}

		// The consent record copies the text as agreed: the activity's text can
		// change later, what this guardian agreed to cannot.
		if (($activity['consentRequired'] ?? false) === true) {
			$row['consent'] = ['statement' => (string)$activity['consentStatement'], 'grantedByRef' => $subjectRef, 'grantedAt' => $now];
		}

		return $this->store->save(schema: ActivityStore::SIGNUP, data: $row);
	}//end writeSignup()

	/**
	 * Whether the consent the activity needs was given: no consent needed, or
	 * the accepted text is exactly the current consent text. An activity that
	 * needs consent but has no text accepts nobody.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param string $acceptedStatement The text the guardian agreed to.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-an-activity-must-be-able-to-require-a-guardians-consent-recorded-on-the-sign-up
	 */
	private function consentAccepted(array $activity, string $acceptedStatement): bool {
		if (($activity['consentRequired'] ?? false) !== true) {
			return true;
		}

		$statement = (string)($activity['consentStatement'] ?? '');
		return $statement !== '' && $acceptedStatement === $statement;
	}//end consentAccepted()

	/**
	 * Withdraw a child's sign-up; a freed place goes to the waiting list.
	 *
	 * Only a guardian of the child may withdraw, and only on an activity in
	 * their reach. A withdrawn waitlisted child frees no place.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $activityId The activity id or slug.
	 * @param string $childRef The child.
	 *
	 * @return string|null A REASON_* constant, or null on success.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function withdraw(string $subjectRef, string $activityId, string $childRef): ?string {
		$activity = $this->feedReader->readOwnActivity(subjectRef: $subjectRef, activityId: $activityId);
		if ($activity === null || $this->feedReader->isOwnChild(subjectRef: $subjectRef, childRef: $childRef) === false) {
			return self::REASON_NOT_FOUND;
		}

		$signup = $this->places->activeFor(signups: ($this->signupsOf(activity: $activity) ?? []), childRef: $childRef);
		if ($signup === null) {
			return self::REASON_NOT_FOUND;
		}

		$saved = $this->store->save(
			schema: ActivityStore::SIGNUP,
			data: array_merge($signup, ['status' => ActivityPlaces::WITHDRAWN, 'withdrawnAt' => $this->now()]),
			id: $this->store->idOf(row: $signup)
		);
		if ($saved === null) {
			return self::REASON_UNAVAILABLE;
		}

		if ($signup['status'] === ActivityPlaces::CONFIRMED) {
			$this->promote(activity: $activity);
		}

		return null;
	}//end withdraw()

	/**
	 * Confirm waitlisted children, longest waiting first, until the places
	 * are full. Returns how many were promoted.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function promote(array $activity): int {
		$signups = $this->signupsOf(activity: $activity);
		if ($signups === null) {
			return 0;
		}

		$free = ($this->places->placesFor(activity: $activity) - count($this->places->confirmed(signups: $signups)));
		$promoted = 0;
		foreach ($this->places->waitlist(signups: $signups) as $waiting) {
			if ($promoted >= $free) {
				break;
			}

			$saved = $this->store->save(
				schema: ActivityStore::SIGNUP,
				data: array_merge($waiting, ['status' => ActivityPlaces::CONFIRMED, 'confirmedAt' => $this->now()]),
				id: $this->store->idOf(row: $waiting)
			);
			if ($saved !== null) {
				$promoted++;
			}
		}

		return $promoted;
	}//end promote()

	/**
	 * Replace an activity's supervisors; more places promote from the list.
	 *
	 * Fewer supervisors never takes a place away from a child who has one;
	 * it only stops new confirmations.
	 *
	 * @param string $activityId The activity id or slug.
	 * @param array<int, mixed> $supervisorRefs The new supervisor references.
	 *
	 * @return array{activity: array<string, mixed>, promoted: int}|null Null when the activity is unknown or the write failed.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function setSupervisors(string $activityId, array $supervisorRefs): ?array {
		$activity = $this->store->lookup(schema: ActivityStore::OFFER, id: $activityId);
		if ($activity === null) {
			return null;
		}

		$refs = array_values(array_unique(array_filter($supervisorRefs, static fn ($ref) => is_string($ref) === true && $ref !== '')));
		$saved = $this->store->save(
			schema: ActivityStore::OFFER,
			data: array_merge($activity, ['supervisorRefs' => $refs]),
			id: $this->store->idOf(row: $activity)
		);
		if ($saved === null) {
			return null;
		}

		return ['activity' => $saved, 'promoted' => $this->promote(activity: $saved)];
	}//end setSupervisors()

	/**
	 * Whether an activity takes sign-ups now: open, and before its deadline.
	 * An unreadable deadline closes it.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 *
	 * @return bool
	 */
	private function acceptsSignups(array $activity): bool {
		if (($activity['status'] ?? '') !== 'open') {
			return false;
		}

		$deadline = (string)($activity['signupDeadline'] ?? '');
		if ($deadline === '') {
			return true;
		}

		$deadlineAt = strtotime($deadline);
		return $deadlineAt !== false && $this->time->getTime() <= $deadlineAt;
	}//end acceptsSignups()

	/**
	 * The status a new sign-up gets: a place, the waiting list, or null (full).
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param array<int, array<string, mixed>> $signups The activity's sign-ups.
	 *
	 * @return string|null
	 */
	private function statusForNewSignup(array $activity, array $signups): ?string {
		if (count($this->places->confirmed(signups: $signups)) < $this->places->placesFor(activity: $activity)) {
			return ActivityPlaces::CONFIRMED;
		}

		if (($activity['waitlistEnabled'] ?? false) === true) {
			return ActivityPlaces::WAITLISTED;
		}

		return null;
	}//end statusForNewSignup()

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

	/**
	 * Now, as ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate('c', $this->time->getTime());
	}//end now()
}//end class
