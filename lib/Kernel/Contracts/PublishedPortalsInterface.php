<?php

/**
 * Contract of PublishedPortals
 *
 * The public surface of PublishedPortals that other components call, so they depend
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
 * Contract of PublishedPortals.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublishedPortalsInterface {

	/**
	 * Every published portal.
	 *
	 * Memoised for the life of this instance, i.e. for the request (review of
	 * #516): `site()` composes its page from five helpers that each resolve
	 * the portal themselves, and without this each one re-ran a
	 * `findAll(limit: 500)` against OpenRegister on every public page load.
	 *
	 * Only an ANSWERED read is memoised. The three `return []` paths are not:
	 * two of them are transient (OpenRegister threw, or the shared
	 * ObjectService could not be pointed at this app's schemas) and caching
	 * them would turn one unlucky read into a 404 for the whole request,
	 * which is a worse failure than the duplicate reads this removes.
	 *
	 * @return array The published portal objects.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function allPublishedPortals(): array;
}//end interface
