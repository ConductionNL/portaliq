<?php

/**
 * Portaliq Destination Mapping
 *
 * Turns a form binding and its form's fields into the mapping OpenRegister's
 * submit service and validator read: which answer fills which property of
 * the destination, and which values the server sets that no answer can
 * override.
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

/**
 * Builds a binding's mapping onto its destination.
 *
 * Nothing here names an app or a procedure (decision 182): the destination,
 * the property that holds the subject and any fixed values are the
 * binding's configuration.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationMapping {
	/**
	 * The campaign fields a landing-page form always whitelists. They travel only
	 * to a destination that declares them: another destination would refuse each
	 * as a property it does not have, and the whole submission with it.
	 */
	public const TRACKING_FIELDS = ['utmFirstTouch', 'utmLastTouch', 'referrer'];

	/**
	 * What a binding check puts in the subject property, standing in for the
	 * subject reference only a submit knows.
	 */
	public const SUBJECT_PLACEHOLDER = 'subject';

	/**
	 * The key a finding without a field of its own is filed under.
	 */
	public const FORM_LEVEL = '_form';

	/**
	 * The destination a binding names, or null when it names none.
	 *
	 * Reads `destination { register, schema }`. A binding the repair step has
	 * not reached yet still carries `caseRegister` and `caseSchema`; those
	 * are read as its destination, so a binding works across the upgrade.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array{register: string, schema: string}|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function destinationOf(array $binding): ?array {
		$destination = $binding['destination'] ?? null;
		$register    = '';
		$schema      = '';
		if (is_array($destination) === true) {
			$register = trim((string)($destination['register'] ?? ''));
			$schema   = trim((string)($destination['schema'] ?? ''));
		}

		if ($register === '' && $schema === '') {
			$register = trim((string)($binding['caseRegister'] ?? ''));
			$schema   = trim((string)($binding['caseSchema'] ?? ''));
		}

		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end destinationOf()

	/**
	 * The mapping a submit or a check sends.
	 *
	 * - `fields`: every named field of the form, writing into the property of
	 *   the same name, or the property the field or the binding's
	 *   `destination.fields` names. The field's own type, options and bounds
	 *   travel along, so the validator can judge them.
	 * - `fixed`: the binding's `destination.fixed` values; the binding's type
	 *   id in the destination property that references the binding's type
	 *   schema; the subject reference in `destination.subjectField`; and each
	 *   server value the destination declares a property for.
	 *
	 * @param array<string, mixed>             $binding    The binding.
	 * @param array<int, array<string, mixed>> $fields     The form's fields.
	 * @param array<string, mixed>|null        $schema     The destination schema, with `properties`; null when unread.
	 * @param string                           $subjectRef The subject reference, or '' when anonymous.
	 * @param array<string, mixed>             $server     Values the server knows (statements, decisions, ...), by name.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public function mappingFor(array $binding, array $fields, ?array $schema, string $subjectRef = '', array $server = []): array {
		$destination = (array)($binding['destination'] ?? []);
		$properties  = (array)($schema['properties'] ?? []);

		$fixed = $this->declaredOnly(values: $server, properties: $properties);

		$typeProperty = $this->typeProperty(binding: $binding, properties: $properties);
		if ($typeProperty !== null) {
			$fixed[$typeProperty] = (string)$binding['typeId'];
		}

		$subjectField = trim((string)($destination['subjectField'] ?? ''));
		if ($subjectField !== '' && $subjectRef !== '') {
			$fixed[$subjectField] = $subjectRef;
		}

		foreach ((array)($destination['fixed'] ?? []) as $property => $value) {
			$fixed[(string)$property] = $value;
		}

		$fields = array_values(
			array_filter(
				$fields,
				static fn (mixed $field): bool => is_array($field) === false
					|| in_array((string)($field['name'] ?? ''), self::TRACKING_FIELDS, true) === false
					|| $properties === []
					|| array_key_exists((string)$field['name'], $properties) === true
			)
		);

		return ['fields' => $this->fieldsOf(fields: $fields, renames: (array)($destination['fields'] ?? [])), 'fixed' => $fixed];
	}//end mappingFor()

	/**
	 * `fieldMeta` marking each field the portal worked out or a decision
	 * filled as `computed`, so the destination can tell them from typed answers.
	 *
	 * @param array<int, mixed> $computed The names of those fields.
	 *
	 * @return array<string, array{computed: bool}>
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t07
	 */
	public function fieldMeta(array $computed): array {
		$meta = [];
		foreach (array_filter($computed, 'is_string') as $name) {
			$meta[$name] = ['computed' => true];
		}

		return $meta;
	}//end fieldMeta()

	/**
	 * The server values the destination declares a property for, without empty ones.
	 *
	 * @param array<string, mixed> $values     The server values, by name.
	 * @param array<string, mixed> $properties The destination's properties.
	 *
	 * @return array<string, mixed>
	 */
	private function declaredOnly(array $values, array $properties): array {
		$kept = [];
		foreach ($values as $name => $value) {
			if (array_key_exists((string)$name, $properties) === true && $value !== null && $value !== [] && $value !== '') {
				$kept[(string)$name] = $value;
			}
		}

		return $kept;
	}//end declaredOnly()

	/**
	 * The answers per field: each finding filed under the field that writes
	 * its property, so the form shows the destination's message on that
	 * question. A finding no field answers for is filed under `_form`.
	 *
	 * @param array<int, array<string, mixed>> $findings The destination's findings.
	 * @param array<string, mixed>             $mapping  The mapping that was sent.
	 *
	 * @return array<string, string> Message per field name.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public function fieldErrors(array $findings, array $mapping): array {
		$byProperty = [];
		foreach ((array)($mapping['fields'] ?? []) as $field) {
			if (is_array($field) === true) {
				$byProperty[(string)($field['property'] ?? '')] = (string)($field['field'] ?? '');
			}
		}

		$errors = [];
		foreach ($findings as $finding) {
			$field = (string)($finding['field'] ?? '');
			if ($field === '') {
				$field = ($byProperty[(string)($finding['property'] ?? '')] ?? '');
			}

			if ($field === '') {
				$field = self::FORM_LEVEL;
			}

			if (isset($errors[$field]) === false) {
				$errors[$field] = (string)($finding['message'] ?? '');
			}
		}

		return $errors;
	}//end fieldErrors()

	/**
	 * The mapping's fields.
	 *
	 * @param array<int, array<string, mixed>> $fields  The form's fields.
	 * @param array<string, mixed>             $renames Field name => property, from the binding.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fieldsOf(array $fields, array $renames): array {
		$out = [];
		foreach ($fields as $field) {
			$name = '';
			if (is_array($field) === true) {
				$name = trim((string)($field['name'] ?? ''));
			}

			if ($name === '') {
				continue;
			}

			$property = trim((string)($renames[$name] ?? $field['property'] ?? ''));
			if ($property === '') {
				$property = $name;
			}

			$field['field']    = $name;
			$field['property'] = $property;
			$out[] = $field;
		}

		return $out;
	}//end fieldsOf()

	/**
	 * The destination property that references the binding's type schema,
	 * when the binding names a type id and the destination has one.
	 *
	 * @param array<string, mixed> $binding    The binding.
	 * @param array<string, mixed> $properties The destination's properties.
	 *
	 * @return string|null
	 */
	private function typeProperty(array $binding, array $properties): ?string {
		$typeSchema = trim((string)($binding['typeSchema'] ?? ''));
		$typeId     = trim((string)($binding['typeId'] ?? ''));
		if ($typeSchema === '' || $typeId === '') {
			return null;
		}

		foreach ($properties as $name => $property) {
			$ref = '';
			if (is_array($property) === true) {
				// A slug (`caseType`) or a path ending in one (`#/components/schemas/caseType`).
				$ref = (string)($property['$ref'] ?? '');
				$ref = substr($ref, (int)strrpos('/' . $ref, '/'));
			}

			if ($ref !== '' && $ref === $typeSchema) {
				return (string)$name;
			}
		}

		return null;
	}//end typeProperty()
}//end class
