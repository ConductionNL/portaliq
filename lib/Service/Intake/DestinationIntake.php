<?php

/**
 * Portaliq Destination Intake
 *
 * One bound form, submitted into its destination or checked against it. The
 * intake controller, the embed controller and the drain all go through here,
 * so a form is judged and written the same way on every path.
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

use OCA\Portaliq\Service\PortalSchemaReader;

/**
 * Submits a bound form into its destination, or checks the binding.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationIntake {
	/**
	 * The finding code for a binding that names no destination.
	 */
	public const NO_DESTINATION = 'destination-missing';

	/**
	 * The finding code for a destination that could not be checked.
	 */
	public const UNCHECKED = 'destination-unreachable';

	/**
	 * Constructor.
	 *
	 * @param DestinationSubmit  $submitter Calls OpenRegister's submit service and validator.
	 * @param DestinationMapping $mapping   Builds the binding's mapping.
	 * @param PortalSchemaReader $schemas   Reads the destination schema.
	 */
	public function __construct(
		private readonly DestinationSubmit $submitter,
		private readonly DestinationMapping $mapping,
		private readonly PortalSchemaReader $schemas,
	) {
	}//end __construct()

	/**
	 * Submit the answers of a bound form into its destination.
	 *
	 * @param array<string, mixed> $binding        The binding.
	 * @param array<string, mixed> $render         What the binding renders to (its `fields`).
	 * @param array<string, mixed> $answers        The answers the portal's own checks accepted.
	 * @param string               $subjectRef     The signed-in subject, or '' when anonymous.
	 * @param array<string, mixed> $server         Values the server knows, carried where the destination declares them.
	 * @param string               $idempotencyKey The client's Idempotency-Key, or ''.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, errors: array<string, string>}
	 *         `errors` holds a message per field for a 422.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
	 */
	public function submit(
		array $binding,
		array $render,
		array $answers,
		string $subjectRef = '',
		array $server = [],
		string $idempotencyKey = '',
	): array {
		$destination = $this->mapping->destinationOf(binding: $binding);
		if ($destination === null) {
			return ['ok' => false, 'status' => 503, 'answer' => [], 'message' => 'The form names no destination.', 'errors' => []];
		}

		$mapping = $this->mapping->mappingFor(
			binding: $binding,
			fields: (array)($render['fields'] ?? []),
			schema: $this->schemas->readSchema(slug: $destination['schema']),
			subjectRef: $subjectRef,
			server: $server
		);

		$scope = (string)($binding['portal'] ?? '') . '/' . (string)($binding['route'] ?? '');
		$result = $this->submitter->submit(
			destination: $destination,
			mapping: $mapping,
			payload: $answers,
			idempotencyKey: $idempotencyKey,
			scope: $scope
		);

		$errors = [];
		if ($result['ok'] === false && $result['status'] === 422) {
			$errors = $this->mapping->fieldErrors(findings: $result['findings'], mapping: $mapping);
		}

		return [
			'ok' => $result['ok'],
			'status' => $result['status'],
			'answer' => $result['answer'],
			'message' => $result['message'],
			'errors' => $errors,
		];
	}//end submit()

	/**
	 * The answer to a submit that did not create the destination object.
	 *
	 * 422: the destination's message on each refused field; nothing was
	 * stored. 403/404: the form cannot be sent. Anything else, and no submit
	 * at all: the request did not arrive, the page keeps the answers and says
	 * to try again later. Nothing is queued (decision 181).
	 *
	 * @param array<string, mixed>|null $submitted What submit() answered, or null when there was no intake.
	 *
	 * @return array{body: array<string, mixed>, status: int}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public static function refusal(?array $submitted): array {
		$status = (int)($submitted['status'] ?? 503);
		if ($status === 422) {
			return ['body' => ['error' => 'refused_by_destination', 'errors' => (array)($submitted['errors'] ?? [])], 'status' => 422];
		}

		if ($status === 403 || $status === 404) {
			return ['body' => ['error' => 'form_not_submittable'], 'status' => $status];
		}

		return ['body' => ['error' => 'try_again_later'], 'status' => 503];
	}//end refusal()

	/**
	 * The findings that stop a binding from being published.
	 *
	 * A binding without a destination, and a destination that could not be
	 * checked, are findings too: an unchecked binding is not a valid one.
	 * A binding whose intake happens elsewhere (external) has nothing to check.
	 *
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render  What the binding renders to.
	 *
	 * @return array<int, array<string, mixed>> The findings; empty when the binding may be published.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function check(array $binding, array $render): array {
		if ((string)($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL) {
			return [];
		}

		$destination = $this->mapping->destinationOf(binding: $binding);
		if ($destination === null) {
			return [[
				'property' => '',
				'code' => self::NO_DESTINATION,
				'message' => 'This form does not say which register and schema its answers go to.',
			],
			];
		}

		$mapping = $this->mapping->mappingFor(
			binding: $binding,
			fields: (array)($render['fields'] ?? []),
			schema: $this->schemas->readSchema(slug: $destination['schema']),
			subjectRef: DestinationMapping::SUBJECT_PLACEHOLDER
		);

		// Every portal submit reaches OpenRegister as an anonymous, unowned
		// write (DestinationSubmit), so the destination must take one.
		$findings = $this->submitter->findings(destination: $destination, mapping: $mapping, audience: 'public');
		if ($findings === null) {
			return [[
				'property' => '',
				'code' => self::UNCHECKED,
				'message' => sprintf('The destination %s/%s could not be checked. Is its app installed?', $destination['register'], $destination['schema']),
			],
			];
		}

		return $findings;
	}//end check()
}//end class
