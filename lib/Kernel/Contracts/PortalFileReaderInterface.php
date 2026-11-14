<?php

/**
 * Contract of PortalFileReader
 *
 * The public surface of PortalFileReader that other components call, so they depend
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

use OCP\AppFramework\Http\StreamResponse;

/**
 * Contract of PortalFileReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFileReaderInterface {

	/**
	 * List the files attached to an object the subject already owns, as safe
	 * metadata only (id/name/size — the raw stored path is never included).
	 *
	 * This is EVERY file in the object's folder, including what staff added and
	 * never released. It is for the server's own use (finding a free name for an
	 * upload) and for a collection that opted into `filesDownload` on an object
	 * the subject wrote; a screen that shows a resident the organisation's
	 * files uses listReleasedFiles() instead (portaliq#798).
	 *
	 * The caller MUST have re-verified ownership through the scoped reader
	 * before calling this. Degrades to an empty list on any OR error or when
	 * OpenRegister is unavailable — a listing failure never surfaces as an
	 * error to the client, it simply shows no files.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug/id.
	 * @param string $id The owned object's id (ownership already verified).
	 *
	 * @return array<int, array<string, mixed>> The attached files' safe metadata.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#identical-404-discipline-no-existence-oracle
	 */
	public function listFiles(string $register, string $schema, string $id): array;

	/**
	 * Stream a file attached to an object the subject already owns.
	 *
	 * The caller MUST have re-verified ownership through the scoped reader
	 * before calling this (a foreign or non-existent object id must never
	 * reach here). The file is resolved STRICTLY within the owned object's own
	 * folder (`FileService::getFile()`), so a `fileId` belonging to a
	 * different object's folder — or no file at all — resolves to null, never
	 * a stream. Fails closed to null on any OR error.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug/id.
	 * @param string $id The owned object's id (ownership already verified).
	 * @param string $fileId The requested file's id (never trusted as a
	 *                       capability; scoped to the owned object's folder).
	 *
	 * @return StreamResponse|null The stream response, or null when the file
	 *                             cannot be resolved (identical to "does not
	 *                             exist" — no existence oracle).
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
	 */
	public function streamFile(string $register, string $schema, string $id, string $fileId): ?StreamResponse;
}//end interface
