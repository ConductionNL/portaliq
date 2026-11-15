<?php

/**
 * Contract of CaseTypeReader
 *
 * The public surface of CaseTypeReader that other components call, so they depend
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
 * Contract of CaseTypeReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeReaderInterface {

	/**
	 * Read one case type by id.
	 *
	 * @param string $register The register the case type lives in.
	 * @param string $schema The schema the case type lives in.
	 * @param string $id The case type id, taken from the case's own type field.
	 *
	 * @return array<string, mixed>|null The case type, or null.
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function readCaseType(string $register, string $schema, string $id): ?array;
}//end interface
