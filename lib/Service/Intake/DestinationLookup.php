<?php

/**
 * Portaliq Destination Lookup
 *
 * Finds the object a form submitted into, from the reference the resident
 * holds, across the destinations a portal's published bindings name.
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

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSchemaReader;

/**
 * Reads a destination object by reference.
 *
 * Two ways in, and only two:
 * - an earlier reference (`AANVRAAG-…`, `WOO-…`) the drain copied onto the
 *   object's `externalReference`. Those were random, so holding one is proof
 *   enough to read its state, as it was before (decision 181, Q10);
 * - the destination's own reference (the property marked
 *   `x-openregister.reference`), for the signed-in subject whose reference
 *   the binding's `destination.subjectField` holds. A case number counts up,
 *   so it is never enough on its own.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationLookup {
	/**
	 * The property the drain copies an earlier reference onto.
	 */
	public const EARLIER_REFERENCE = 'externalReference';

	/**
	 * Constructor.
	 *
	 * @param PortalFormBindingResolver $bindings The portal's published bindings.
	 * @param PortalObjectReader        $reader   Reads destination objects.
	 * @param PortalSchemaReader        $schemas  Reads a destination's markers.
	 * @param DestinationMapping        $mapping  Says where a binding submits.
	 */
	public function __construct(
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalObjectReader $reader,
		private readonly PortalSchemaReader $schemas,
		private readonly DestinationMapping $mapping,
	) {
	}//end __construct()

	/**
	 * The destination object behind a reference, or null.
	 *
	 * @param string $portal     The portal slug.
	 * @param string $reference  The reference the resident holds.
	 * @param string $subjectRef The signed-in subject, or '' when anonymous.
	 *
	 * @return array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.2
	 */
	public function find(string $portal, string $reference, string $subjectRef = ''): ?array {
		$reference = trim($reference);
		if ($portal === '' || $reference === '') {
			return null;
		}

		foreach ($this->destinations(portal: $portal) as $entry) {
			$found = $this->findIn(entry: $entry, reference: $reference, subjectRef: $subjectRef);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}//end find()

	/**
	 * What the reference page may say about a found object: the state the
	 * old page answered, plus the confirmation properties the destination
	 * marks (case number, received moment, term start, deadline).
	 *
	 * @param array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>} $found     What find() answered.
	 * @param string                                                                                           $reference The reference asked for.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
	 */
	public function statusOf(array $found, string $reference): array {
		$object       = $found['object'];
		$confirmation = [];
		foreach ($this->marked(schema: $found['schema'], marker: 'confirmation') as $property) {
			if (array_key_exists($property, $object) === true) {
				$confirmation[$property] = $object[$property];
			}
		}

		$own = '';
		foreach ($this->marked(schema: $found['schema'], marker: 'reference') as $property) {
			$own = (string)($object[$property] ?? '');
			break;
		}

		$receivedAt = (string)($confirmation['receivedAt'] ?? '');

		return [
			'reference' => $reference,
			'state' => PortalIntakeQueue::STATE_REGISTERED,
			'caseId' => $own,
			'externalReference' => (string)($object[self::EARLIER_REFERENCE] ?? ''),
			'dueAt' => (string)($confirmation['deadline'] ?? ''),
			'failureReason' => '',
			'submittedAt' => $receivedAt,
			'confirmation' => $confirmation,
		];
	}//end statusOf()

	/**
	 * The distinct destinations of a portal's published bindings, each with
	 * the first binding that names it and its schema.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array{destination: array{register: string, schema: string}, binding: array<string, mixed>, schema: array<string, mixed>}>
	 */
	private function destinations(string $portal): array {
		$seen = [];
		foreach ($this->bindings->publishedBindings(portal: $portal) as $binding) {
			$destination = $this->mapping->destinationOf(binding: $binding);
			if ($destination === null) {
				continue;
			}

			$key = $destination['register'] . '/' . $destination['schema'];
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = [
				'destination' => $destination,
				'binding' => $binding,
				'schema' => ($this->schemas->readSchema(slug: $destination['schema']) ?? []),
			];
		}

		return array_values($seen);
	}//end destinations()

	/**
	 * The object in one destination, by earlier reference or, for its owner, by its own.
	 *
	 * @param array<string, mixed> $entry      The destination: `destination`, `binding` and `schema`.
	 * @param string               $reference  The reference.
	 * @param string               $subjectRef The signed-in subject, or ''.
	 *
	 * @return array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>}|null
	 */
	private function findIn(array $entry, string $reference, string $subjectRef): ?array {
		$destination = $entry['destination'];
		$schema      = $entry['schema'];
		$properties  = (array)($schema['properties'] ?? []);

		if (array_key_exists(self::EARLIER_REFERENCE, $properties) === true) {
			$row = $this->first(
				rows: $this->reader->readCollection(
					register: $destination['register'],
					schema: $destination['schema'],
					scopeField: self::EARLIER_REFERENCE,
					subjectRef: $reference,
					limit: 2
				),
				property: self::EARLIER_REFERENCE,
				value: $reference
			);
			if ($row !== null) {
				return ['object' => $row, 'binding' => $entry['binding'], 'schema' => $schema];
			}
		}

		$subjectField = trim((string)($entry['binding']['destination']['subjectField'] ?? ''));
		$own          = $this->marked(schema: $schema, marker: 'reference');
		if ($subjectRef === '' || $subjectField === '' || $own === []) {
			return null;
		}

		$row = $this->first(
			rows: $this->reader->readCollection(
				register: $destination['register'],
				schema: $destination['schema'],
				scopeField: $subjectField,
				subjectRef: $subjectRef,
				limit: 2,
				filter: [$own[0] => $reference]
			),
			property: $own[0],
			value: $reference
		);
		if ($row === null || (string)($row[$subjectField] ?? '') !== $subjectRef) {
			return null;
		}

		return ['object' => $row, 'binding' => $entry['binding'], 'schema' => $schema];
	}//end findIn()

	/**
	 * The first row whose property holds exactly the value: a reader's
	 * filter is not trusted to be exact.
	 *
	 * @param array<int, mixed> $rows     The rows.
	 * @param string            $property The property.
	 * @param string            $value    The value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(array $rows, string $property, string $value): ?array {
		foreach ($rows as $row) {
			if (is_array($row) === true && (string)($row[$property] ?? '') === $value) {
				return $row;
			}
		}

		return null;
	}//end first()

	/**
	 * The properties a schema marks with an `x-openregister` marker, in order.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $marker The marker name.
	 *
	 * @return array<int, string>
	 */
	private function marked(array $schema, string $marker): array {
		$names = [];
		foreach ((array)($schema['properties'] ?? []) as $name => $property) {
			if (is_array($property) === true && (($property['x-openregister'][$marker] ?? false) === true)) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end marked()
}//end class
