<?php

/**
 * Portaliq Destination Submit
 *
 * Hands a form's answers to OpenRegister's FormSubmitService, which validates
 * them against the destination schema and creates the destination object in
 * the same request (decision 179). portaliq writes nothing of its own: no
 * queue entry, no intake object.
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Submits into a destination, and checks a mapping against one.
 *
 * Both OpenRegister services are resolved by name, so portaliq stays
 * installable without OpenRegister: then a submit answers 503 ("try again
 * later") and a check answers null ("could not be checked"), never success.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationSubmit {
	/**
	 * OpenRegister's submit service.
	 */
	private const SUBMIT_SERVICE = 'OCA\\OpenRegister\\Service\\Form\\FormSubmitService';

	/**
	 * OpenRegister's build-time destination validator.
	 */
	private const VALIDATOR = 'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator';

	/**
	 * OpenRegister's schema mapper, to hand the validator a schema entity.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

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
	 * @param LoggerInterface    $logger    Records why a destination could not be reached.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Submit answers into a destination.
	 *
	 * The subject is passed as null: a portal resident is not a Nextcloud
	 * user. Who it is reaches the object as a server-set value in the
	 * mapping's `fixed` block (DestinationMapping), which no answer can
	 * override, and the create runs under OpenRegister's RBAC as an
	 * unowned, anonymous write, never with RBAC switched off.
	 *
	 * @param array{register: string, schema: string} $destination    The destination pair.
	 * @param array<string, mixed>                    $mapping        `fields` and `fixed`.
	 * @param array<string, mixed>                    $payload        The answers, by field name.
	 * @param string                                  $idempotencyKey The client's Idempotency-Key, or ''.
	 * @param string                                  $scope          What the key and upload tokens belong to.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 *         On success `answer` holds reference, id, receivedAt and confirmation.
	 *         On a refusal `status` is 422, 403, 404 or 503 and `answer` is empty.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
	 */
	public function submit(array $destination, array $mapping, array $payload, string $idempotencyKey = '', string $scope = ''): array {
		$service = $this->resolve(name: self::SUBMIT_SERVICE);
		if ($service === null) {
			return $this->refusal(status: 503, message: 'The destination cannot be reached.', findings: []);
		}

		$key = null;
		if ($idempotencyKey !== '') {
			$key = $idempotencyKey;
		}

		try {
			$answer = $service->submit(
				destination: $destination,
				mapping: $mapping,
				payload: $payload,
				subject: null,
				idempotencyKey: $key,
				scope: $scope
			);
		} catch (Throwable $exception) {
			return $this->refusalOf(exception: $exception, destination: $destination);
		}

		if (is_array($answer) === false) {
			return $this->refusal(status: 503, message: 'The destination gave no answer.', findings: []);
		}

		return ['ok' => true, 'status' => 201, 'answer' => $answer, 'message' => '', 'findings' => []];
	}//end submit()

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
			return $this->refusal(status: 503, message: 'The destination cannot be reached.', findings: []);
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
			return $this->refusalOf(exception: $exception, destination: $destination);
		}

		if (is_array($answer) === false || (string)($answer['id'] ?? '') === '') {
			return $this->refusal(status: 503, message: 'The destination gave no answer.', findings: []);
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
			return $this->refusal(status: 503, message: 'The destination cannot be reached.', findings: []);
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
			return $this->refusalOf(exception: $exception, destination: $destination);
		}

		if (is_array($answer) === false) {
			return $this->refusal(status: 503, message: 'The destination gave no answer.', findings: []);
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
	 * Check a mapping against its destination schema, as OpenRegister judges it.
	 *
	 * @param array{register: string, schema: string} $destination The destination pair.
	 * @param array<string, mixed>                    $mapping     `fields` and `fixed`.
	 * @param string                                  $audience    `public` or `authenticated`.
	 *
	 * @return array<int, array<string, mixed>>|null The findings (empty when the mapping is
	 *         valid), or null when the destination or the validator could not be reached.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function findings(array $destination, array $mapping, string $audience): ?array {
		$validator = $this->resolve(name: self::VALIDATOR);
		$schema    = $this->schemaEntity(slug: (string)($destination['schema'] ?? ''));
		if ($validator === null || $schema === null) {
			return null;
		}

		try {
			$findings = $validator->validate(mapping: $mapping, schema: $schema, options: ['audience' => $audience]);
		} catch (Throwable $exception) {
			$this->logger->warning('Portaliq: a form binding could not be checked', ['reason' => $exception->getMessage()]);
			return null;
		}

		if (is_array($findings) === false) {
			return null;
		}

		return array_values(array_filter($findings, 'is_array'));
	}//end findings()

	/**
	 * The refusal a thrown submit means.
	 *
	 * OpenRegister's refusal carries its status and findings; anything else
	 * is an unreachable destination, 503, so the resident is told to try
	 * again later and keeps the answers.
	 *
	 * @param Throwable                               $exception   What the submit threw.
	 * @param array{register: string, schema: string} $destination The destination, for the log.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 */
	private function refusalOf(Throwable $exception, array $destination): array {
		if (method_exists($exception, 'getStatus') === true && method_exists($exception, 'getFindings') === true) {
			$status   = (int)$exception->getStatus();
			$findings = (array)$exception->getFindings();
			if ($status >= 400 && $status <= 599) {
				return $this->refusal(status: $status, message: $exception->getMessage(), findings: array_values(array_filter($findings, 'is_array')));
			}
		}

		$this->logger->error(
			'Portaliq: a form submit could not reach its destination',
			['schema' => (string)($destination['schema'] ?? ''), 'reason' => $exception->getMessage()]
		);

		return $this->refusal(status: 503, message: 'The destination cannot be reached.', findings: []);
	}//end refusalOf()

	/**
	 * A refusal in the shape submit() answers.
	 *
	 * @param int                              $status   The status.
	 * @param string                           $message  The sentence.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 */
	private function refusal(int $status, string $message, array $findings): array {
		return ['ok' => false, 'status' => $status, 'answer' => [], 'message' => $message, 'findings' => $findings];
	}//end refusal()

	/**
	 * The destination schema as OpenRegister's entity, or null.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return object|null
	 */
	private function schemaEntity(string $slug): ?object {
		$mapper = $this->resolve(name: self::SCHEMA_MAPPER);
		if ($mapper === null || $slug === '') {
			return null;
		}

		try {
			$matches = $mapper->findBySlug(slug: $slug, limit: 1, offset: 0, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->warning('Portaliq: a destination schema could not be read', ['slug' => $slug, 'reason' => $exception->getMessage()]);
			return null;
		}

		if (is_array($matches) === false || isset($matches[0]) === false || is_object($matches[0]) === false) {
			return null;
		}

		return $matches[0];
	}//end schemaEntity()

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
