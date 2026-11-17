<?php

/**
 * Contract of PortalAdminGrants
 *
 * The public surface of PortalAdminGrants that other components call, so they depend
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
 * Contract of PortalAdminGrants.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAdminGrantsInterface {

	/**
	 * The content schemas a portal owns, keyed by slug, with the field naming the portal.
	 *
	 * @var array<string, string>
	 */
	public const SCHEMAS = [
		'page'         => 'portal',
		'menu'         => 'portal',
		'portalNotice' => 'portal',
		'media'        => 'portal',
		'portal'       => 'slug',
	];

	/**
	 * Write one portal's groups into the rules of every schema it owns.
	 *
	 * @param string             $slug         The portal slug; '' removes the grants of `$previousSlug` only.
	 * @param array<int, string> $groups       The portal's `adminGroups`.
	 * @param string             $previousSlug The slug before this write, whose grants are removed.
	 *
	 * @return bool True when every schema that exists was updated.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function apply(string $slug, array $groups, string $previousSlug = ''): bool;
}//end interface
