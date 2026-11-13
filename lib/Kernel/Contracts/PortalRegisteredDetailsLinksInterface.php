<?php

/**
 * Contract of PortalRegisteredDetailsLinks
 *
 * The public surface of PortalRegisteredDetailsLinks that other components call, so they depend
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
 * Contract of PortalRegisteredDetailsLinks.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRegisteredDetailsLinksInterface {

	/**
	 * The links one portal offers for one kind of record.
	 *
	 * @param array<string, mixed>|null $portal The serving portal, or null.
	 * @param string                    $kind   person or company.
	 *
	 * @return array{correction: ?string, addressInvestigation: ?string}
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-can-ask-for-a-correction-req-ird-004
	 */
	public function forPortal(?array $portal, string $kind): array;
}//end interface
