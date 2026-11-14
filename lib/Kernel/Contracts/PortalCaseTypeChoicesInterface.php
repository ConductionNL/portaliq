<?php

/**
 * Contract of PortalCaseTypeChoices
 *
 * The public surface of PortalCaseTypeChoices that other components call, so they depend
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
 * Contract of PortalCaseTypeChoices.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalCaseTypeChoicesInterface {

	/**
	 * The portal's case types, once each, sorted by title.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array{id: string, title: string}> The choices; a case type that cannot be read is left out.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
	 */
	public function forPortal(string $portal): array;
}//end interface
