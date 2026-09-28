<?php

/**
 * Portaliq Activity Contribution Service (activity-offer-contract-fix)
 *
 * Bills the confirmed places of an activity that asks a contribution. Staff
 * type the amount; portaliq raises one invoice per place through shillinq's
 * contributions raise and writes each returned payment request id into the
 * sign-up's `paymentRequestRef`. The activity is the chargeable, the child the
 * beneficiary, the guardian who signed up the debtor. Portaliq keeps no amount.
 *
 * A second run bills only places that have no reference yet (a waitlist
 * promotion), and a place shillinq already billed answers `skipped` with the
 * standing request, whose id is written too. So a retry is always safe.
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
 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;

/**
 * Raises an activity's contribution per confirmed place and writes the
 * references.
 *
 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
 */
class ActivityContributionService {
	/**
	 * The activity does not exist.
	 */
	public const REASON_NOT_FOUND = 'not_found';

	/**
	 * The activity asks no contribution.
	 */
	public const REASON_NOT_REQUESTED = 'payment_not_requested';

	/**
	 * The sign-ups could not be read.
	 */
	public const REASON_UNAVAILABLE = 'activity_unavailable';

	/**
	 * The raise answers the service passes on from shillinq, named here so a
	 * caller maps one vocabulary.
	 */
	public const ERROR_INVALID = ShillinqContributionRaiser::ERROR_INVALID;

	public const ERROR_FORBIDDEN = ShillinqContributionRaiser::ERROR_FORBIDDEN;

	public const ERROR_SHILLINQ_UNAVAILABLE = ShillinqContributionRaiser::ERROR_UNAVAILABLE;

	public const ERROR_FAILED = ShillinqContributionRaiser::ERROR_FAILED;

	/**
	 * Shillinq's most recipients per call.
	 */
	private const CHUNK = 200;

	/**
	 * The optional charge fields passed on to shillinq as they came.
	 */
	private const OPTIONAL_KEYS = ['invoiceDate', 'dueDate', 'revenueAccount', 'language', 'currency'];

	/**
	 * Constructor.
	 *
	 * @param ActivityStore $store The activity rows.
	 * @param ActivityPlaces $places Selects an activity's sign-ups.
	 * @param PortalAccountLookup $accounts The guardian's portal account.
	 * @param ShillinqContributionRaiser $raiser The shillinq call.
	 */
	public function __construct(
		private readonly ActivityStore $store,
		private readonly ActivityPlaces $places,
		private readonly PortalAccountLookup $accounts,
		private readonly ShillinqContributionRaiser $raiser,
	) {
	}//end __construct()

	/**
	 * Raise the contribution for every confirmed place without a reference.
	 *
	 * @param string $activityId The activity id or slug.
	 * @param array<string, mixed> $params The request: amount, voluntary, administrationId, and the optional keys.
	 *
	 * @return array<string, mixed> `{raised, skipped, failed, results}`, or `['error' => <code>]`.
	 *
	 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
	 */
	public function raise(string $activityId, array $params): array {
		$activity = $this->store->lookup(schema: ActivityStore::OFFER, id: $activityId);
		if ($activity === null) {
			return ['error' => self::REASON_NOT_FOUND];
		}

		if (($activity['paymentRequested'] ?? false) !== true) {
			return ['error' => self::REASON_NOT_REQUESTED];
		}

		$charge = $this->charge(activity: $activity, params: $params);
		if ($charge === null) {
			return ['error' => self::ERROR_INVALID];
		}

		$rows = $this->store->rows(schema: ActivityStore::SIGNUP);
		if ($rows === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		$confirmed = $this->places->confirmed(
			signups: $this->places->forActivity(signups: $rows, activityKeys: $this->store->keys(row: $activity))
		);
		$unbilled = array_values(
			array_filter($confirmed, static fn (array $signup): bool => trim((string)($signup['paymentRequestRef'] ?? '')) === '')
		);

		return $this->raiseFor(signups: $unbilled, charge: $charge);
	}//end raise()

	/**
	 * The charge part of the payload, or null when a required key is missing.
	 *
	 * @param array<string, mixed> $activity The activity.
	 * @param array<string, mixed> $params The request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function charge(array $activity, array $params): ?array {
		if ($this->chargeIsComplete(params: $params) === false) {
			return null;
		}

		$description = trim((string)($params['description'] ?? ''));
		if ($description === '') {
			$description = (string)($activity['title'] ?? '');
		}

		$charge = [
			'chargeable' => [
				'app' => 'portaliq',
				'type' => 'activity-offer',
				'register' => 'portaliq',
				'schema' => ActivityStore::OFFER,
				'id' => $this->store->idOf(row: $activity),
			],
			'kind' => 'activity',
			'description' => $description,
			'amount' => (float)$params['amount'],
			'voluntary' => $params['voluntary'],
			'administrationId' => trim((string)$params['administrationId']),
		];
		foreach (self::OPTIONAL_KEYS as $key) {
			if (is_string($params[$key] ?? null) === true && $params[$key] !== '') {
				$charge[$key] = $params[$key];
			}
		}

		return $charge;
	}//end charge()

	/**
	 * Whether the request carries an amount above zero, a boolean `voluntary`
	 * and an administration.
	 *
	 * @param array<string, mixed> $params The request.
	 *
	 * @return bool
	 */
	private function chargeIsComplete(array $params): bool {
		$amount = ($params['amount'] ?? null);
		$administrationId = ($params['administrationId'] ?? null);

		return is_numeric($amount) === true && (float)$amount > 0.0
			&& is_bool($params['voluntary'] ?? null) === true
			&& is_string($administrationId) === true && trim($administrationId) !== '';
	}//end chargeIsComplete()

	/**
	 * Build the recipients, raise them in chunks, write the references.
	 *
	 * @param array<int, array<string, mixed>> $signups The unbilled confirmed sign-ups.
	 * @param array<string, mixed> $charge The charge part of the payload.
	 *
	 * @return array<string, mixed>
	 */
	private function raiseFor(array $signups, array $charge): array {
		$results = [];
		$sendable = [];
		foreach ($signups as $signup) {
			$debtor = $this->debtor(guardianRef: (string)($signup['guardianRef'] ?? ''));
			if ($debtor === null) {
				$results[] = $this->entry(signup: $signup, status: 'failed', reason: 'no_contact_details');
				continue;
			}

			$sendable[] = [
				'signup' => $signup,
				'recipient' => ['debtor' => $debtor, 'beneficiary' => ['type' => 'learner', 'id' => (string)($signup['childRef'] ?? '')]],
			];
		}

		foreach (array_chunk($sendable, self::CHUNK) as $chunk) {
			$answer = $this->raiser->raise(payload: $charge + ['recipients' => array_column($chunk, 'recipient')]);
			if (isset($answer['error']) === true) {
				return ['error' => $answer['error']];
			}

			foreach ($chunk as $index => $item) {
				$results[] = $this->applied(signup: $item['signup'], result: $this->resultAt(answer: $answer, index: $index));
			}
		}

		return $this->summary(results: $results);
	}//end raiseFor()

	/**
	 * The debtor for a guardian's portal account, or null without a name or
	 * an email (shillinq needs both next to the subject reference).
	 *
	 * @param string $guardianRef The guardian's portal subjectRef.
	 *
	 * @return array{portalSubjectRef: string, name: string, email: string}|null
	 */
	private function debtor(string $guardianRef): ?array {
		$account = $this->accounts->bySubjectRef(subjectRef: $guardianRef);
		if ($account === null) {
			return null;
		}

		$name = trim((string)($account['displayName'] ?? ''));
		$email = trim((string)($account['email'] ?? ''));
		if ($email === '') {
			$email = trim((string)($account['verifiedEmail'] ?? ''));
		}

		if ($name === '' || $email === '') {
			return null;
		}

		return ['portalSubjectRef' => $guardianRef, 'name' => $name, 'email' => $email];
	}//end debtor()

	/**
	 * Shillinq's result for the recipient at an index of the chunk.
	 *
	 * @param array<string, mixed> $answer Shillinq's answer.
	 * @param int $index The recipient's index in the chunk.
	 *
	 * @return array<string, mixed>
	 */
	private function resultAt(array $answer, int $index): array {
		foreach ((array)$answer['results'] as $result) {
			if (is_array($result) === true && ($result['index'] ?? null) === $index) {
				return $result;
			}
		}

		return ['status' => 'failed', 'reason' => 'no_result'];
	}//end resultAt()

	/**
	 * Write the returned reference into the sign-up and describe the outcome.
	 *
	 * @param array<string, mixed> $signup The sign-up.
	 * @param array<string, mixed> $result Shillinq's result for it.
	 *
	 * @return array<string, mixed>
	 */
	private function applied(array $signup, array $result): array {
		$status = (string)($result['status'] ?? 'failed');
		$reference = $result['paymentRequestId'] ?? null;
		if (in_array($status, ['raised', 'skipped'], true) === false || is_string($reference) === false || $reference === '') {
			return $this->entry(signup: $signup, status: 'failed', reason: (string)($result['reason'] ?? 'not_raised'));
		}

		$saved = $this->store->save(
			schema: ActivityStore::SIGNUP,
			data: array_merge($signup, ['paymentRequestRef' => $reference]),
			id: $this->store->idOf(row: $signup)
		);
		$entry = $this->entry(signup: $signup, status: $status);
		$entry['paymentRequestRef'] = $reference;
		if ($saved === null) {
			// Shillinq holds the request; the next raise answers `skipped`
			// with this id and the write is tried again.
			$entry['reason'] = 'write_failed';
		}

		return $entry;
	}//end applied()

	/**
	 * One result line.
	 *
	 * @param array<string, mixed> $signup The sign-up.
	 * @param string $status raised, skipped or failed.
	 * @param string $reason Why it failed, when it did.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(array $signup, string $status, string $reason = ''): array {
		$entry = [
			'signupId' => $this->store->idOf(row: $signup),
			'childRef' => (string)($signup['childRef'] ?? ''),
			'status' => $status,
		];
		if ($reason !== '') {
			$entry['reason'] = $reason;
		}

		return $entry;
	}//end entry()

	/**
	 * The counts and the result lines.
	 *
	 * @param array<int, array<string, mixed>> $results The result lines.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(array $results): array {
		$count = static fn (string $status): int => count(array_filter($results, static fn (array $r): bool => $r['status'] === $status));

		return [
			'raised' => $count('raised'),
			'skipped' => $count('skipped'),
			'failed' => $count('failed'),
			'results' => $results,
		];
	}//end summary()
}//end class
