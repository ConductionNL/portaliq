<?php

/**
 * Contract of SiteOrganisation
 *
 * The public surface of SiteOrganisation that other components call, so they depend
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
 * Contract of SiteOrganisation.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SiteOrganisationInterface {

	/**
	 * The portal's organisation, or null when its slug names none.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return array{id: string, name: string}|null
	 *
	 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-organisation-filter-reads-the-organisations-name
	 */
	public function forPortal(array $portal): ?array;
}//end interface
