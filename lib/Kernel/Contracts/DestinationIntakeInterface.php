<?php

/**
 * Contract of DestinationIntake
 *
 * The public surface of DestinationIntake that other components call, so they depend
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
 * Contract of DestinationIntake.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DestinationIntakeInterface {

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
	public function check(array $binding, array $render): array;

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
	): array;
}//end interface
