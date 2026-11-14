<?php

/**
 * Contract of PortalTimelineReader
 *
 * The public surface of PortalTimelineReader that other components call, so they depend
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
 * Contract of PortalTimelineReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalTimelineReaderInterface {

	/**
	 * The entries the app's provider returns for one object, or null.
	 *
	 * @param string $appId The contributing app.
	 * @param string $method The declared `timeline.provider`.
	 * @param string $id The object id, already proven to be the subject's.
	 *
	 * @return array<int, mixed>|null The entries as the provider returned
	 *                                them, or null when there is no callable
	 *                                method or the call failed.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function entries(string $appId, string $method, string $id): ?array;
}//end interface
