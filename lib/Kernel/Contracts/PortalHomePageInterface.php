<?php

/**
 * Contract of PortalHomePage
 *
 * The public surface of PortalHomePage that other components call, so they depend
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
 * Contract of PortalHomePage.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalHomePageInterface {

	/**
	 * The home-page state of one portal.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array{state: string, route: string, pageId: string|null, pageTitle: string|null}
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
	 */
	public function verdict(string $slug): array;
}//end interface
