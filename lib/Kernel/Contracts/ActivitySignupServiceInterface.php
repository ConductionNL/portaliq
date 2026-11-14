<?php

/**
 * Contract of ActivitySignupService
 *
 * The public surface of ActivitySignupService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of ActivitySignupService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivitySignupServiceInterface {

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function setSupervisors(string $activityId, array $supervisorRefs): ?array;

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-an-activity-must-be-able-to-require-a-guardians-consent-recorded-on-the-sign-up
	 */
	public function signUp(string $subjectRef, string $activityId, string $childRef, string $note = '', string $acceptedStatement = ''): array;

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function withdraw(string $subjectRef, string $activityId, string $childRef): ?string;
}//end interface
