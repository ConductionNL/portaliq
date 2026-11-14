<?php

/**
 * Contract of PublicRecordsNormaliser
 *
 * The public surface of PublicRecordsNormaliser that other components call, so they depend
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
 * Contract of PublicRecordsNormaliser.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicRecordsNormaliserInterface {

	/**
	 * The entries a provider may be called for, with their provider names.
	 *
	 * @param mixed $entries The declared `publicRecords`.
	 * @param object|null $provider The contributing app's provider, for the method check.
	 *
	 * @return array<int, array<string, string>> `{id, label, group, listProvider, recordProvider}` per kept entry.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
	 */
	public function normalise(mixed $entries, ?object $provider): array;
}//end interface
