<?php

/**
 * Contract of NewsAudienceOptions
 *
 * The public surface of NewsAudienceOptions that other components call, so they depend
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
 * Contract of NewsAudienceOptions.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NewsAudienceOptionsInterface {

	/**
	 * The schools and groups on offer, each `{id, label}`, sorted by label.
	 * Empty lists when no school app declares where they come from; the
	 * screen then asks for a reference instead.
	 *
	 * @return array{schools: array<int, array{id: string, label: string}>, groups: array<int, array{id: string, label: string}>}
	 *
	 * @spec openspec/changes/archive/2026-10-07-staff-news-screen/tasks.md#T2
	 */
	public function options(): array;
}//end interface
