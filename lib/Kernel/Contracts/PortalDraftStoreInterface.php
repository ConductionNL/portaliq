<?php

/**
 * Contract of PortalDraftStore
 *
 * The public surface of PortalDraftStore that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PortalDraftStore.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalDraftStoreInterface {

	/**
	 * Delete the drafts whose retention has passed.
	 *
	 * @param int $limit How many drafts one run looks at.
	 * @param DateTimeImmutable|null $now The clock, for tests.
	 *
	 * @return int How many were deleted.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	public function purgeExpired(int $limit = 200, ?DateTimeImmutable $now = null): int;
}//end interface
