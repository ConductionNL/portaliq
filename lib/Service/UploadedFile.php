<?php

/**
 * Portaliq Uploaded File
 *
 * One multipart upload read into a sanitised name and its bytes.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Reads an upload the way every portal upload is read: the client's path is
 * never trusted.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */
final class UploadedFile {
	/**
	 * Read an upload into a {name, content} pair, or null when there is
	 * nothing usable: an error, no readable temporary file, or no bytes.
	 *
	 * The name is reduced to its basename, never a client path, and becomes
	 * `upload` when that leaves nothing.
	 *
	 * @param mixed $uploaded The entry from IRequest::getUploadedFile().
	 *
	 * @return array{name: string, content: string}|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function read(mixed $uploaded): ?array {
		$tmpName = (string)($uploaded['tmp_name'] ?? '');
		if ((int)($uploaded['error'] ?? 1) !== 0 || $tmpName === '' || is_readable($tmpName) === false) {
			return null;
		}

		$content = file_get_contents($tmpName);
		if ($content === false) {
			return null;
		}

		$fileName = basename((string)($uploaded['name'] ?? 'upload'));
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			$fileName = 'upload';
		}

		return ['name' => $fileName, 'content' => $content];
	}//end read()
}//end class
