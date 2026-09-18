<?php

/**
 * Portaliq Portal Intake Queue
 *
 * A valid submission is accepted, recorded and acknowledged with a reference
 * at once. The case is created afterwards, by the background job, because a
 * citizen should not be held on a page while another app writes a row.
 *
 * The state on the submission is the truth the reference page reads. A create
 * that failed says `failed` there, and the page says the request has not been
 * registered yet: nothing here ever states that a case exists before one does.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Records, acknowledges and reports on intake submissions.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalIntakeQueue {
	/**
	 * A submission recorded but not yet registered as a case.
	 */
	public const STATE_QUEUED = 'queued';

	/**
	 * A submission whose case exists.
	 */
	public const STATE_REGISTERED = 'registered';

	/**
	 * A submission whose create failed. Shown, never hidden.
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * The register the submission lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a submission.
	 */
	private const SCHEMA = 'portalIntakeSubmission';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads a submission by its reference.
	 * @param PortalObjectWriter $writer Records and updates the submission.
	 * @param ISecureRandom $random Mints the reference.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Accept a validated submission and hand back its reference.
	 *
	 * @param string $portal The portal it was submitted on.
	 * @param string $route The form page it came from.
	 * @param array<string, mixed> $answers The validated answers.
	 * @param string $subjectRef The portal identity, or '' when anonymous.
	 * @param string $origin The framing origin, or '' on the portal itself.
	 *
	 * @return array{reference: string, state: string}|null Null when the
	 *         submission could not be recorded, in which case nothing is
	 *         acknowledged: a reference nobody can look up is worse than none.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function accept(string $portal, string $route, array $answers, string $subjectRef = '', string $origin = ''): ?array {
		if ($portal === '') {
			return null;
		}

		$reference = 'AANVRAAG-' . strtoupper($this->random->generate(8, (ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS)));
		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'reference' => $reference,
				'portal' => $portal,
				'route' => $route,
				'subjectRef' => $subjectRef,
				'origin' => $origin,
				'answers' => $answers,
				'state' => self::STATE_QUEUED,
				'submittedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		return ['reference' => $reference, 'state' => self::STATE_QUEUED];
	}//end accept()

	/**
	 * What the reference page may say about a submission.
	 *
	 * @param string $reference The reference the citizen was given.
	 * @param string $portal The portal, re-checked against the row.
	 *
	 * @return array<string, mixed>|null The state, and the reason when it
	 *         failed. Null when no submission carries that reference.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function status(string $reference, string $portal): ?array {
		$row = $this->byReference(reference: $reference, portal: $portal);
		if ($row === null) {
			return null;
		}

		$state = (string)($row['state'] ?? self::STATE_QUEUED);

		return [
			'reference' => (string)($row['reference'] ?? ''),
			'state' => $state,
			// A case id is answered only when there IS a case. The page cannot
			// print one it was never given.
			'caseId' => ($state === self::STATE_REGISTERED ? (string)($row['caseId'] ?? '') : ''),
			'failureReason' => ($state === self::STATE_FAILED ? (string)($row['failureReason'] ?? '') : ''),
			'submittedAt' => (string)($row['submittedAt'] ?? ''),
		];
	}//end status()

	/**
	 * The submissions still waiting for their case.
	 *
	 * @param int $limit How many to take.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function queued(int $limit = 50): array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'state',
			subjectRef: self::STATE_QUEUED,
			organisation: '',
			limit: $limit
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['state'] ?? '') === self::STATE_QUEUED) {
				$out[] = $row;
			}
		}

		return $out;
	}//end queued()

	/**
	 * Record that a submission became a case.
	 *
	 * @param array<string, mixed> $submission The submission row.
	 * @param string $caseId The case that now exists.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function markRegistered(array $submission, string $caseId): bool {
		return $this->write(
			submission: $submission,
			data: [
				'state' => self::STATE_REGISTERED,
				'caseId' => $caseId,
				'registeredAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
	}//end markRegistered()

	/**
	 * Record that a submission's create failed, with the reason.
	 *
	 * @param array<string, mixed> $submission The submission row.
	 * @param string $reason Why it failed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function markFailed(array $submission, string $reason): bool {
		return $this->write(submission: $submission, data: ['state' => self::STATE_FAILED, 'failureReason' => $reason]);
	}//end markFailed()

	/**
	 * The submission carrying a reference, within one portal.
	 *
	 * @param string $reference The reference.
	 * @param string $portal The portal.
	 *
	 * @return array<string, mixed>|null
	 */
	private function byReference(string $reference, string $portal): ?array {
		if ($reference === '' || $portal === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'reference',
			subjectRef: $reference,
			organisation: '',
			limit: 5,
			filter: ['portal' => $portal]
		);

		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['reference'] ?? '') === $reference && ($row['portal'] ?? '') === $portal) {
				return $row;
			}
		}

		return null;
	}//end byReference()

	/**
	 * Write on a submission row.
	 *
	 * @param array<string, mixed> $submission The submission row.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return bool
	 */
	private function write(array $submission, array $data): bool {
		$id = (string)($submission['uuid'] ?? $submission['id'] ?? '');
		if ($id === '') {
			$self = (array)($submission['@self'] ?? []);
			$id = (string)($self['uuid'] ?? $self['id'] ?? '');
		}

		if ($id === '') {
			return false;
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: $data
		) !== null;
	}//end write()
}//end class
