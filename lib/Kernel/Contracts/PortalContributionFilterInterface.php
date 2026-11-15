<?php

/**
 * Contract of PortalContributionFilter
 *
 * The public surface of PortalContributionFilter that other components call, so they depend
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
 * Contract of PortalContributionFilter.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalContributionFilterInterface {

	/**
	 * Keep only the contributions that belong on `$portalSlug`.
	 *
	 * The parameter is `array<int, mixed>` and NOT `array<int, array<…>>`,
	 * which phpstan is right to insist on: the aggregate is assembled from
	 * third-party providers reached by a duck-typed call (ADR-046), so a
	 * member really can be a string, a null, or anything else a broken
	 * provider returned. Declaring the stricter type made the `is_array()`
	 * guard below dead code by contract while it was load-bearing in fact —
	 * a type that promises what the boundary cannot deliver.
	 *
	 * @param array<int, mixed> $contributions The aggregate, as received.
	 * @param string            $portalSlug    The serving portal's slug.
	 *
	 * @return array<int, array<string, mixed>> The contributions for this portal.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-contribution-must-be-scoped-to-the-portal-it-targets
	 */
	public function forPortal(array $contributions, string $portalSlug): array;
}//end interface
