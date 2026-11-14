<?php

/**
 * Contract of PortalDraftDrain
 *
 * The public surface of PortalDraftDrain that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PortalDraftDrain.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalDraftDrainInterface {

	/**
	 * Drain up to `$limit` rows.
	 *
	 * @param int                    $limit How many rows to read.
	 * @param DateTimeImmutable|null $now   The moment, for tests.
	 *
	 * @return array{converted: int, expired: int, refused: int, reasons: array<string, string>, waiting: int}
	 *         `waiting` counts the rows still there afterwards.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
	 */
	public function drain(int $limit = 200, ?DateTimeImmutable $now = null): array;

	/**
	 * How many rows wait to be drained.
	 *
	 * @param int $limit How many rows to read.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
	 */
	public function waiting(int $limit = 200): int;
}//end interface
