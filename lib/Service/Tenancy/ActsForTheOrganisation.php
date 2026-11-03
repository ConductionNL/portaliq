<?php

/**
 * Portaliq ActsForTheOrganisation
 *
 * OpenRegister's object service acting for the portal's organisation, for a
 * class that reads or writes for a portal subject. The using class holds the
 * container in `$this->container` and the logger in `$this->logger`.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use Throwable;

/**
 * The object service for the portal's organisation, one context per instance.
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
 */
trait ActsForTheOrganisation {

	/**
	 * The context, made on first use.
	 *
	 * @var PortalTenantContext|null
	 */
	private ?PortalTenantContext $tenantContext = null;

	/**
	 * The object service acting for the organisation with this slug, or null
	 * when there is none to act with; see PortalTenantContext::objectServiceFor().
	 *
	 * @param string $organisation The organisation slug, or ''.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
	 */
	private function actingObjectService(string $organisation): ?object {
		try {
			return $this->tenantContext()->objectServiceFor(organisation: $organisation);
		} catch (Throwable $e) {
			return null;
		}
	}//end actingObjectService()

	/**
	 * The object service acting for the organisation with this uuid, or null
	 * when there is none to act with; see PortalTenantContext::objectServiceForUuid().
	 *
	 * @param string $organisationUuid The organisation uuid, or ''.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-claim-addressed-recipients-are-found-in-the-records-organisation-req-pao-003
	 */
	private function actingObjectServiceForUuid(string $organisationUuid): ?object {
		try {
			return $this->tenantContext()->objectServiceForUuid(organisationUuid: $organisationUuid);
		} catch (Throwable $e) {
			return null;
		}
	}//end actingObjectServiceForUuid()

	/**
	 * The tenant context of this instance.
	 *
	 * @return PortalTenantContext
	 */
	private function tenantContext(): PortalTenantContext {
		if ($this->tenantContext === null) {
			$this->tenantContext = new PortalTenantContext(container: $this->container, logger: $this->logger);
		}

		return $this->tenantContext;
	}//end tenantContext()
}//end trait
