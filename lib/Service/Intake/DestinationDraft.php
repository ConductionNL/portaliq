<?php

/**
 * Portaliq Destination Draft
 *
 * Keeps a resident's saved answers as a draft of the object the form
 * creates: the destination object itself, in OpenRegister status `draft`
 * (decision 180), owned by `portal:<subjectRef>` (decision 195). Saves,
 * finds, sends and discards it through OpenRegister's FormSubmitService and
 * DraftDiscard, resolved by name so portaliq stays installable without
 * OpenRegister: then every call refuses (503, null or false), never success.
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A portal subject's draft in a destination.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
class DestinationDraft {
	/**
	 * OpenRegister's submit service.
	 */
	private const SUBMIT_SERVICE = 'OCA\\OpenRegister\\Service\\Form\\FormSubmitService';

	/**
	 * OpenRegister's draft discard (decision 195).
	 */
	private const DRAFT_DISCARD = 'OCA\\OpenRegister\\Service\\Object\\DraftDiscard';

	/**
	 * OpenRegister's draft owner, built as `portal:<subjectRef>` (decision 195).
	 */
	private const DRAFT_OWNER = 'OCA\\OpenRegister\\Service\\Object\\DraftOwner';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services.
	 * @param LoggerInterface    $logger    Records why a draft could not be read or discarded.
	 * @param DestinationSubmit  $submit    Turns OpenRegister's refusals into answers.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DestinationSubmit $submit,
	) {
	}//end __construct()

	/**
	 * Save the subject's draft of a destination: the destination object itself, in status `draft`.
	 *
	 * Decision 180: the draft may miss required answers, never hold a
	 * type-invalid one (422 with findings). Decision 195: its owner is
	 * `portal:<subjectRef>`, so only this subject reads, saves or sends it.
	 *
	 * @param array{register: string, schema: string} $destination The destination pair.
	 * @param array<string, mixed>                    $payload     The answers so far, by property.
	 * @param string                                  $subjectRef  The signed-in subject.
	 * @param string                                  $draftId     The draft to update, or '' to start one.
	 * @param string                                  $scope       What upload tokens belong to.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 *         On success `answer` holds the draft's `id`.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function saveDraft(array $destination, array $payload, string $subjectRef, string $draftId = '', string $scope = ''): array {
		$service = $this->resolve(name: self::SUBMIT_SERVICE);
		$owner   = $this->ownerOf(subjectRef: $subjectRef);
		if ($service === null || $owner === null) {
			return $this->submit->refusal(status: 503, message: 'The destination cannot be reached.', findings: []);
		}

		try {
			$answer = $service->saveDraft(
				destination: $destination,
				mapping: null,
				payload: $payload,
				subject: null,
				draftId: self::orNull(value: $draftId),
				scope: $scope,
				owner: $owner
			);
		} catch (Throwable $exception) {
			return $this->submit->refusalOf(exception: $exception, destination: $destination);
		}

		if (is_array($answer) === false || (string)($answer['id'] ?? '') === '') {
			return $this->submit->refusal(status: 503, message: 'The destination gave no answer.', findings: []);
		}

		return ['ok' => true, 'status' => 200, 'answer' => $answer, 'message' => '', 'findings' => []];
	}//end saveDraft()

	/**
	 * The subject's newest draft in a destination whose data holds each `$match` value, or null.
	 *
	 * @param array{register: string, schema: string} $destination The destination pair.
	 * @param string                                  $subjectRef  The signed-in subject.
	 * @param array<string, mixed>                    $match       Values the draft must hold, such as an action's defaults.
	 *
	 * @return array<string, mixed>|null `{id, status, data, updatedAt}`, or null when there is none or nothing answers.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function findDraft(array $destination, string $subjectRef, array $match = []): ?array {
		$service = $this->resolve(name: self::SUBMIT_SERVICE);
		$owner   = $this->ownerOf(subjectRef: $subjectRef);
		if ($service === null || $owner === null) {
			return null;
		}

		try {
			$draft = $service->findDraft(destination: $destination, subject: null, owner: $owner, match: $match);
		} catch (Throwable $exception) {
			$this->logger->warning('Portaliq: a draft could not be looked up', ['reason' => $exception->getMessage()]);
			return null;
		}

		if (is_array($draft) === false || (string)($draft['id'] ?? '') === '') {
			return null;
		}

		return $draft;
	}//end findDraft()

	/**
	 * Send the subject's draft: it leaves `draft` with full validation and gets its reference.
	 *
	 * @param array{register: string, schema: string} $destination    The destination pair.
	 * @param array<string, mixed>                    $payload        The final answers, by property.
	 * @param string                                  $subjectRef     The signed-in subject.
	 * @param string                                  $draftId        The draft.
	 * @param string                                  $idempotencyKey The client's Idempotency-Key, or ''.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function sendDraft(array $destination, array $payload, string $subjectRef, string $draftId, string $idempotencyKey = ''): array {
		$service = $this->resolve(name: self::SUBMIT_SERVICE);
		$owner   = $this->ownerOf(subjectRef: $subjectRef);
		if ($service === null || $owner === null || $draftId === '') {
			return $this->submit->refusal(status: 503, message: 'The destination cannot be reached.', findings: []);
		}

		try {
			$answer = $service->submit(
				destination: $destination,
				mapping: null,
				payload: $payload,
				subject: null,
				idempotencyKey: self::orNull(value: $idempotencyKey),
				draftId: $draftId,
				owner: $owner
			);
		} catch (Throwable $exception) {
			return $this->submit->refusalOf(exception: $exception, destination: $destination);
		}

		if (is_array($answer) === false) {
			return $this->submit->refusal(status: 503, message: 'The destination gave no answer.', findings: []);
		}

		return ['ok' => true, 'status' => 201, 'answer' => $answer, 'message' => '', 'findings' => []];
	}//end sendDraft()

	/**
	 * Discard the subject's draft. Someone else's draft, or a sent object, is left alone.
	 *
	 * @param array{register: string, schema: string} $destination The destination pair.
	 * @param string                                  $subjectRef  The signed-in subject.
	 * @param string                                  $draftId     The draft.
	 *
	 * @return bool True when it was removed.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function discardDraft(array $destination, string $subjectRef, string $draftId): bool {
		$discard = $this->resolve(name: self::DRAFT_DISCARD);
		$owner   = $this->ownerOf(subjectRef: $subjectRef);
		if ($discard === null || $owner === null || $draftId === '') {
			return false;
		}

		try {
			return $discard->discard(destination: $destination, draftId: $draftId, owner: $owner) === true;
		} catch (Throwable $exception) {
			$this->logger->warning('Portaliq: a draft could not be discarded', ['reason' => $exception->getMessage()]);
			return false;
		}
	}//end discardDraft()

	/**
	 * The draft owner for a portal subject: OpenRegister's `portal:<subjectRef>`, or null.
	 *
	 * Null when the reference is empty or does not fit an owner, or when
	 * OpenRegister is not installed; every draft call then refuses.
	 *
	 * @param string $subjectRef The signed-in subject.
	 *
	 * @return object|null The owner.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	protected function ownerOf(string $subjectRef): ?object {
		$factory = [self::DRAFT_OWNER, 'portalSubject'];
		if ($subjectRef === '' || is_callable($factory) === false) {
			return null;
		}

		try {
			$owner = $factory($subjectRef);
		} catch (Throwable) {
			return null;
		}

		if (is_object($owner) === false) {
			return null;
		}

		return $owner;
	}//end ownerOf()

	/**
	 * A value, or null for ''.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()

	/**
	 * A refusal's findings per field, for the form: a create action's field is its property.
	 *
	 * @param array<int, array<string, mixed>> $findings The destination's findings.
	 *
	 * @return array<string, string> The first message per field; '_form' for a finding without a property.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function errorsOf(array $findings): array {
		$errors = [];
		foreach ($findings as $finding) {
			$parts = explode('.', str_replace('/', '.', trim((string)($finding['property'] ?? ''), './')));
			$field = $parts[0];
			if ($field === '') {
				$field = '_form';
			}

			if (isset($errors[$field]) === false) {
				$errors[$field] = (string)($finding['message'] ?? '');
			}
		}

		return $errors;
	}//end errorsOf()

	/**
	 * An OpenRegister service by name, or null when it is not there.
	 *
	 * @param string $name The class name.
	 *
	 * @return object|null
	 */
	private function resolve(string $name): ?object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end resolve()
}//end class
