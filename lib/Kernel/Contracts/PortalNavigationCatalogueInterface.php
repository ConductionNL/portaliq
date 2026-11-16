<?php

/**
 * Contract of PortalNavigationCatalogue
 *
 * The public surface of PortalNavigationCatalogue that other components call, so they depend
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
 * Contract of PortalNavigationCatalogue.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalNavigationCatalogueInterface {

	/**
	 * The portal with this slug, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function portalBySlug(string $slug): ?array;

	/**
	 * Per audience, the menu pages the installed apps contribute, in the
	 * portal's chosen order, each with whether the portal hides it.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return list<array{audience: string, pages: list<array<string, mixed>>}>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function listFor(array $portal): array;

	/**
	 * Store the portal's navigation choice.
	 *
	 * @param array<string, mixed> $portal     The portal.
	 * @param mixed                $navigation Per audience, a list of `{page, hidden}`.
	 *
	 * @return array<string, mixed>|null The saved portal, or null when the write failed.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function save(array $portal, mixed $navigation): ?array;
}//end interface
