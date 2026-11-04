<?php

/**
 * Portaliq OrganisationScopedObjectService
 *
 * OpenRegister's object service with every call run inside
 * `ObjectService::runForOrganisation()` for one organisation. Arguments,
 * named or positional, are passed on unchanged, and so are return values and
 * exceptions.
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

/**
 * The object service, acting for one organisation on every call.
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
 */
final class OrganisationScopedObjectService {

	/**
	 * Constructor.
	 *
	 * @param object $objectService    OpenRegister's ObjectService.
	 * @param string $organisationUuid The organisation the calls act for.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $organisationUuid,
	) {
	}//end __construct()

	/**
	 * Run the call on the object service, acting for the organisation.
	 *
	 * @param string            $name      The ObjectService method.
	 * @param array<int|string, mixed> $arguments Its arguments, positional or named.
	 *
	 * @return mixed What the method returned.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-a-portal-read-or-write-runs-as-its-organisation-req-pao-001
	 */
	public function __call(string $name, array $arguments): mixed {
		$objectService = $this->objectService;

		return $objectService->runForOrganisation(
			organisationUuid: $this->organisationUuid,
			operation: static fn () => $objectService->$name(...$arguments)
		);
	}//end __call()
}//end class
