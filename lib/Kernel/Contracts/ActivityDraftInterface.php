<?php

/**
 * Contract of ActivityDraft
 *
 * The public surface of ActivityDraft that other components call, so they depend
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
 * Contract of ActivityDraft.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityDraftInterface {

	/**
	 * The draft to store, or null when a required field is unsound.
	 *
	 * Required: a title, a kind from KINDS, a target naming at least one
	 * school, group or child, a term start and a capacity of at least one.
	 * The status is always `draft` and the author is the signed-in staff
	 * member, whatever the parameters say.
	 *
	 * @param array{title: string, kind: string, target: array<string, mixed>, termStart: string, capacity: int} $required The required fields.
	 * @param array<string, mixed> $params The request parameters, for the optional fields.
	 * @param string $author The staff member creating it.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	public function build(array $required, array $params, string $author): ?array;
}//end interface
