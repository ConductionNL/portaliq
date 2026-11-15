<?php

/**
 * Contract of DestinationLookup
 *
 * The public surface of DestinationLookup that other components call, so they depend
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
 * Contract of DestinationLookup.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DestinationLookupInterface {

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
	public function find(string $portal, string $reference, string $subjectRef = ''): ?array;

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
	public function statusOf(array $found, string $reference): array;
}//end interface
