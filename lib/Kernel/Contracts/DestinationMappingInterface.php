<?php

/**
 * Contract of DestinationMapping
 *
 * The public surface of DestinationMapping that other components call, so they depend
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
 * Contract of DestinationMapping.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DestinationMappingInterface {

	/**
	 * What a binding check puts in the subject property, standing in for the
	 * subject reference only a submit knows.
	 */
	public const SUBJECT_PLACEHOLDER = 'subject';

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
	public function destinationOf(array $binding): ?array;

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
	public function mappingFor(array $binding, array $fields, ?array $schema, string $subjectRef = '', array $server = []): array;

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
	public function fieldMeta(array $computed): array;
}//end interface
