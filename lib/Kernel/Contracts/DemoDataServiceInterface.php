<?php

/**
 * Contract of DemoDataService
 *
 * The public surface of DemoDataService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of DemoDataService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DemoDataServiceInterface {

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app ships.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Import the demo dataset.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. The caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened" must
	 * not be presentable as success.
	 *
	 * 🔴 COUNTS WHAT LANDED, NOT WHAT WAS ASKED FOR. OpenRegister SKIPS an object
	 * whose schema it cannot resolve instead of failing the import, so a count
	 * taken from the file reports success for a run that seeded nothing — the
	 * wizard said "Imported 39 demo object(s)" while every one of them had been
	 * skipped (WOO-558). `objects` is therefore the importer's own tally of
	 * objects it wrote (or found already present); the file's count travels
	 * separately as `declared`, and the gap as `skipped`, so the operator sees
	 * the discrepancy instead of a number that merely repeats their request.
	 * A descriptor that declares objects and seeds NONE of them is a failure,
	 * the same rule OpenRegister's own RegisterDescriptorService applies.
	 *
	 * @return array{objects: integer, declared: integer, skipped: integer, present: integer, registers: integer, schemas: integer}
	 *   `objects` = what landed, `declared` = what the file holds, `skipped` =
	 *   what the importer refused, `present` = what the demo schemas hold when
	 *   nothing landed, plus the register and schema tallies.
	 *
	 * @throws RuntimeException When the descriptor is missing, unreadable, or
	 *   OpenRegister is absent — or when it declares objects and none landed.
	 *
	 * @spec exclude Demo-data import; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function install(): array;

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so the label, the
	 * description and the object count come from the descriptor that will
	 * actually be imported. A manifest that restated them could disagree with
	 * what lands, and nothing would notice.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array;
}//end interface
