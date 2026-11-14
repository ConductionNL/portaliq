<?php

/**
 * Contract of PortalFileWriter
 *
 * The public surface of PortalFileWriter that other components call, so they depend
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
 * Contract of PortalFileWriter.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFileWriterInterface {

	/**
	 * The tag on a file a resident sent through the portal, so the case screen
	 * can list it apart from what staff put in the same folder
	 * (cases-documents-on-the-case, REQ-CDC-004).
	 *
	 * @var string
	 */
	public const TAG_FROM_APPLICANT = 'portal:from-applicant';

	/**
	 * Attach an uploaded file to an object the subject already owns.
	 *
	 * The caller MUST have re-verified ownership through the scoped reader before
	 * calling this (a foreign or non-existent id must never reach here). The file
	 * lands in the object's OpenRegister folder via `FileService::addFile()`, RBAC
	 * bypassed (Portaliq is the trusted scoper). Fails closed to null on any OR
	 * error.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $id The owned object's id (ownership already verified).
	 * @param string $fileName The sanitised upload filename.
	 * @param string $content The raw file bytes.
	 * @param array<int, string> $tags Tags OpenRegister puts on the file, such as TAG_FROM_APPLICANT.
	 *
	 * @return array<string, mixed>|null The attached file's metadata, or null on failure.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
	 */
	public function attachFile(
		string $register,
		string $schema,
		string $id,
		string $fileName,
		string $content,
		array $tags = [],
	): ?array;
}//end interface
