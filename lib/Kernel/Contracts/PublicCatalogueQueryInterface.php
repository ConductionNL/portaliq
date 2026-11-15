<?php

/**
 * Contract of PublicCatalogueQuery
 *
 * The public surface of PublicCatalogueQuery that other components call, so they depend
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
 * Contract of PublicCatalogueQuery.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicCatalogueQueryInterface {

	/**
	 * The sorts a visitor may choose.
	 */
	public const SORTS = ['relevance', 'date', 'dateDesc', 'title'];

	/**
	 * One page of the items that match a visitor's query, with the facets of
	 * the items that match everything else, and how many there are in all.
	 *
	 * `types` keeps only those item types; `q` matches words in the title,
	 * kind, summary and meta lines (accents and case do not matter);
	 * `filters` maps a facet label to the values that count (within one facet
	 * any value counts, across facets all must); `upcoming` keeps items whose
	 * (end) date is today or later, with the day given as `today`.
	 *
	 * @param array<int, array<string, mixed>> $items  The portal's items.
	 * @param array<string, mixed>             $params `q`, `types`, `filters`, `sort`, `page`, `limit`, `upcoming`, `today`,
	 *                                                 `kindFacet`, `kindNews`, `audienceFacet`.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, facets: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
	 */
	public function run(array $items, array $params): array;
}//end interface
