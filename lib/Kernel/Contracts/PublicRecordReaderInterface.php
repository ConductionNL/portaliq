<?php

/**
 * Contract of PublicRecordReader
 *
 * The public surface of PublicRecordReader that other components call, so they depend
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
 * Contract of PublicRecordReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicRecordReaderInterface {

	/**
	 * The entries of one record list, or null when the app declares no such list.
	 *
	 * @param string $app The contributing app.
	 * @param string $list The list id.
	 *
	 * @return array<int, array<string, string>>|null The entries.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 */
	public function entries(string $app, string $list): ?array;

	/**
	 * One record, or null when the list does not hold the id.
	 *
	 * The record provider is not called for an id the list answer lacks.
	 *
	 * @param string $app The contributing app.
	 * @param string $list The list id.
	 * @param string $id The record id.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 */
	public function record(string $app, string $list, string $id): ?array;
}//end interface
