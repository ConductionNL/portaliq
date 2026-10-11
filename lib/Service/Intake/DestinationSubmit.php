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
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public function refusalOf(Throwable $exception, array $destination): array {
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
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public function refusal(int $status, string $message, array $findings): array {
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
