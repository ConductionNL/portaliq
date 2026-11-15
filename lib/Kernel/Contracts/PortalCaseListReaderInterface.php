<?php

/**
 * Contract of PortalCaseListReader
 *
 * The public surface of PortalCaseListReader that other components call, so they depend
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
 * Contract of PortalCaseListReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalCaseListReaderInterface {

	/**
	 * Every case the subject may see, across every contributing app.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 * @param array<int, string> $hiddenCaseTypes The case type ids the serving
	 *                                            portal does not show.
	 *
	 * @return array<int, array<string, mixed>> The merged case rows.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function listCases(array $subject, array $aggregate, array $hiddenCaseTypes = []): array;

	/**
	 * The cases a mandate opens, on top of the subject's own.
	 *
	 * A mandate reads a collection by the party field the collection declares
	 * (`mandateField`), never by the subject field: the whole point is that
	 * these are somebody else's cases, read because a mandate says so. A
	 * collection that declares no party field is skipped, so an app that never
	 * thought about mandates cannot leak its rows through one. Every row names
	 * the mandate that granted it.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 * @param array<int, array<string, mixed>> $mandates The live mandates.
	 * @param array<int, string> $hiddenCaseTypes The case type ids the serving
	 *                                            portal does not show.
	 *
	 * @return array<int, array<string, mixed>> The mandated case rows.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function listMandatedCases(array $subject, array $aggregate, array $mandates, array $hiddenCaseTypes = []): array;
}//end interface
