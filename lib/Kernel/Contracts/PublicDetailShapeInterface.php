<?php

/**
 * Contract of PublicDetailShape
 *
 * The public surface of PublicDetailShape that other components call, so they depend
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
 * Contract of PublicDetailShape.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicDetailShapeInterface {

	/**
	 * Keep what fits; null when the answer is not an array.
	 *
	 * @param mixed $answer What the app returned.
	 *
	 * @return array<string, mixed>|null The detail, possibly empty of sections.
	 *
	 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-1
	 */
	public function detail(mixed $answer): ?array;

	/**
	 * The contract method an app may implement.
	 */
	public const METHOD = 'getPublicDetail';
}//end interface
