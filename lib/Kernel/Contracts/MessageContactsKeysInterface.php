<?php

/**
 * Contract of MessageContactsKeys
 *
 * The public surface of MessageContactsKeys that other components call, so they depend
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
 * Contract of MessageContactsKeys.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessageContactsKeysInterface {

	/**
	 * A provider's answer for one row, held to `{staffRef, name, role}`: an
	 * entry without a person or a name is dropped, never passed on as it came.
	 *
	 * @param array<int|string, mixed> $entries The provider's answer.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
	 */
	public function contacts(array $entries): array;
}//end interface
