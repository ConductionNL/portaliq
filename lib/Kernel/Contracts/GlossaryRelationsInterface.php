<?php

/**
 * Contract of GlossaryRelations
 *
 * The public surface of GlossaryRelations that other components call, so they depend
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
 * Contract of GlossaryRelations.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GlossaryRelationsInterface {

	/**
	 * Why a term's relations are refused, or null when each one names a term of the same portal.
	 *
	 * @param string                           $portal     The portal the written term belongs to.
	 * @param array<int, mixed>                $relations  The ids the term relates to.
	 * @param array<int, array<string, mixed>> $portalTerms Every term stored on that portal.
	 *
	 * @return string[] The relation ids that do not resolve to a term of this portal.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-cms-content-model/tasks.md#task-3
	 */
	public function foreign(string $portal, array $relations, array $portalTerms): array;
}//end interface
