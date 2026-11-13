<?php

/**
 * Contract of CitizenDocumentUpload
 *
 * The public surface of CitizenDocumentUpload that other components call, so they depend
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
 * Contract of CitizenDocumentUpload.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenDocumentUploadInterface {

	/**
	 * Read the multipart upload into a {name, content} pair, or null when
	 * there is nothing usable. The client's path is never trusted.
	 *
	 * @param array<string, mixed>|null $uploaded The entry from IRequest::getUploadedFile().
	 *
	 * @return array{name: string, content: string}|null
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function read(?array $uploaded): ?array;

	/**
	 * A name no document already on the case uses, so an upload adds rather
	 * than replaces.
	 *
	 * @param array<int, array<string, mixed>> $existing The files already on the case.
	 * @param string                           $fileName The sanitised upload name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function uniqueName(array $existing, string $fileName): string;
}//end interface
