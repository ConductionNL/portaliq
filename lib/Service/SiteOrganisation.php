<?php

/**
 * Portaliq Site Organisation
 *
 * The id and the name of the organisation a portal belongs to, as a visitor
 * may read them: a public search names the organisation that published a
 * record by this id (woo-search-names-the-organisation).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-search-names-the-organisation/specs/portal-federated-search/spec.md#requirement-the-organisation-filter-reads-the-organisations-name
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Names a portal's organisation by id and name.
 *
 * @spec openspec/changes/woo-search-names-the-organisation/specs/portal-federated-search/spec.md#requirement-the-organisation-filter-reads-the-organisations-name
 */
class SiteOrganisation {
	/**
	 * Constructor.
	 *
	 * @param PortalOrganisationConfigService $organisations Resolves an organisation by its slug.
	 */
	public function __construct(
		private readonly PortalOrganisationConfigService $organisations,
	) {
	}//end __construct()

	/**
	 * The portal's organisation, or null when its slug names none.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return array{id: string, name: string}|null
	 *
	 * @spec openspec/changes/woo-search-names-the-organisation/specs/portal-federated-search/spec.md#requirement-the-organisation-filter-reads-the-organisations-name
	 */
	public function forPortal(array $portal): ?array {
		$slug = (string)($portal['organisation'] ?? '');
		if ($slug === '') {
			return null;
		}

		$resolved = $this->organisations->resolve(orgSlug: $slug);
		$id       = (string)($resolved['organisationId'] ?? '');
		if ($id === '') {
			return null;
		}

		return ['id' => $id, 'name' => (string)($resolved['organisationName'] ?? '')];
	}//end forPortal()
}//end class
