<?php

/**
 * Portaliq File Attachment
 *
 * One file to attach to an object the subject already owns: where the
 * object lives, the file's name and bytes, and the tags it carries.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * What one PortalFileWriter::attachFile() call attaches.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
 */
final class FileAttachment {
	/**
	 * Constructor.
	 *
	 * @param string             $register The register slug/id.
	 * @param string             $schema   The schema slug.
	 * @param string             $id       The owned object's id (ownership already verified).
	 * @param string             $fileName The sanitised upload filename.
	 * @param string             $content  The raw file bytes.
	 * @param array<int, string> $tags     Tags OpenRegister puts on the file.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $id,
		public readonly string $fileName,
		public readonly string $content,
		public readonly array $tags,
	) {
	}//end __construct()
}//end class
