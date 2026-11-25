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

use OCA\Portaliq\Kernel\Contracts\DestinationLookupInterface;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;

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
class DestinationLookup implements DestinationLookupInterface {
	/**
	 * The property the drain copies an earlier reference onto.
	 */
	public const EARLIER_REFERENCE = 'externalReference';

	/**
	 * How many of a subject's own rows the uuid reference is looked for in.
	 */
	private const OWN_ROWS = 200;

	/**
	 * Constructor.
	 *
	 * @param PortalFormBindingResolver $bindings The portal's published bindings.
	 * @param ScopedCollectionReaderInterface        $reader   Reads destination objects.
	 * @param PortalSchemaReader        $schemas  Reads a destination's markers.
	 * @param DestinationMapping        $mapping  Says where a binding submits.
	 */
	public function __construct(
		private readonly PortalFormBindingResolver $bindings,
		private readonly ScopedCollectionReaderInterface $reader,
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
		$found = $this->byEarlierReference(entry: $entry, reference: $reference);
		if ($found !== null) {
			return $found;
		}

		return $this->byOwnReference(entry: $entry, reference: $reference, subjectRef: $subjectRef);
	}//end findIn()

	/**
	 * The object in one destination by an earlier reference, when its schema
	 * carries one.
	 *
	 * @param array<string, mixed> $entry     The destination: `destination`, `binding` and `schema`.
	 * @param string               $reference The reference.
	 *
	 * @return array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>}|null
	 */
	private function byEarlierReference(array $entry, string $reference): array|null {
		$schema     = $entry['schema'];
		$properties = (array)($schema['properties'] ?? []);
		if (array_key_exists(self::EARLIER_REFERENCE, $properties) === false) {
			return null;
		}

		$row = $this->earlierRow(destination: $entry['destination'], reference: $reference);
		if ($row === null) {
			return null;
		}

		return ['object' => $row, 'binding' => $entry['binding'], 'schema' => $schema];
	}//end byEarlierReference()

	/**
	 * The row of a destination whose earlier reference is exactly the reference.
	 *
	 * @param array<string, mixed> $destination The destination's `register` and `schema`.
	 * @param string               $reference   The reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function earlierRow(array $destination, string $reference): array|null {
		return $this->first(
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
	}//end earlierRow()

	/**
	 * The object in one destination by its own reference, for its owner only.
	 *
	 * @param array<string, mixed> $entry      The destination: `destination`, `binding` and `schema`.
	 * @param string               $reference  The reference.
	 * @param string               $subjectRef The signed-in subject, or ''.
	 *
	 * @return array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>}|null
	 */
	private function byOwnReference(array $entry, string $reference, string $subjectRef): array|null {
		$destination = $entry['destination'];
		$schema      = $entry['schema'];

		$subjectField = trim((string)($entry['binding']['destination']['subjectField'] ?? ''));
		if ($subjectRef === '' || $subjectField === '') {
			return null;
		}

		$row = $this->byMarkedReference(entry: $entry, subjectField: $subjectField, subjectRef: $subjectRef, reference: $reference)
			?? $this->byUuidReference(
				destination: $destination,
				subjectField: $subjectField,
				subjectRef: $subjectRef,
				reference: $reference
			);
		if ($this->isNotTheSubjects(row: $row, subjectField: $subjectField, subjectRef: $subjectRef) === true) {
			return null;
		}

		return ['object' => $row, 'binding' => $entry['binding'], 'schema' => $schema];
	}//end byOwnReference()

	/**
	 * The subject's row whose reference property holds the reference, or null.
	 *
	 * @param array<string, mixed> $entry        The destination: `destination`, `binding` and `schema`.
	 * @param string               $subjectField The binding's subject property.
	 * @param string               $subjectRef   The signed-in subject.
	 * @param string               $reference    The reference.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	private function byMarkedReference(array $entry, string $subjectField, string $subjectRef, string $reference): ?array {
		$destination = $entry['destination'];
		$own         = $this->marked(schema: $entry['schema'], marker: 'reference');
		if ($own === []) {
			return null;
		}

		return $this->first(
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
	}//end byMarkedReference()

	/**
	 * The subject's row whose uuid is the reference, or null.
	 *
	 * OpenRegister's form submit answers the destination's reference property,
	 * or the object's uuid when that property is empty or absent. A resident
	 * then holds the uuid, and the reference page has to find the case by it
	 * (found live, 25 Nov 2026: a registered submit's reference answered 404).
	 * Only the subject's own rows are read.
	 *
	 * @param array<string, string> $destination  The destination register and schema.
	 * @param string                $subjectField The binding's subject property.
	 * @param string                $subjectRef   The signed-in subject.
	 * @param string                $reference    The reference.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	private function byUuidReference(array $destination, string $subjectField, string $subjectRef, string $reference): ?array {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) !== 1) {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: $destination['register'],
			schema: $destination['schema'],
			scopeField: $subjectField,
			subjectRef: $subjectRef,
			limit: self::OWN_ROWS
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && strcasecmp($this->uuidOf(row: $row), $reference) === 0) {
				return $row;
			}
		}

		return null;
	}//end byUuidReference()

	/**
	 * The uuid of a row as the reader returns it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function uuidOf(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? ''));
	}//end uuidOf()

	/**
	 * Whether a row read is absent or not the signed-in subject's.
	 *
	 * @param array<string, mixed>|null $row          The row, or null.
	 * @param string                    $subjectField The field naming the subject.
	 * @param string                    $subjectRef   The signed-in subject.
	 *
	 * @return bool
	 */
	private function isNotTheSubjects(array|null $row, string $subjectField, string $subjectRef): bool {
		return $row === null || (string)($row[$subjectField] ?? '') !== $subjectRef;
	}//end isNotTheSubjects()

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
