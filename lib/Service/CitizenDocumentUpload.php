<?php

/**
 * Portaliq Citizen Document Upload
 *
 * Reading the multipart upload a citizen attaches to their own case, and
 * giving it a name that does not collide with what the case already holds.
 *
 * Split out of CitizenCaseController, which crossed phpmd's class-complexity
 * threshold (54 against 50) once the citizen-write surface landed. Both
 * routines are pure functions of their arguments -- the naming rule needs the
 * existing file list, the reader needs the uploaded-file array -- so they are
 * static and the controller's constructor is untouched. Splitting them into
 * further private methods INSIDE the controller would have made the finding
 * worse, not better: ExcessiveClassComplexity sums the class, so each extra
 * method adds its own base complexity on top.
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Reads and names a citizen's document upload. Stateless by design.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
final class CitizenDocumentUpload {
	/**
	 * The name used when the client supplies nothing usable.
	 */
	private const FALLBACK_NAME = 'upload';

	/**
	 * Names that are a path traversal rather than a file name.
	 */
	private const UNUSABLE_NAMES = ['', '.', '..'];

	/**
	 * Read the multipart upload into a {name, content} pair, or null when
	 * there is nothing usable. The client's path is never trusted.
	 *
	 * @param array<string, mixed>|null $uploaded The uploaded-file array, as
	 *                                            IRequest::getUploadedFile()
	 *                                            returns it.
	 *
	 * @return array{name: string, content: string}|null The upload, or null.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public static function read(?array $uploaded): ?array {
		$tmpName = (string)($uploaded['tmp_name'] ?? '');
		if ((int)($uploaded['error'] ?? 1) !== 0 || $tmpName === '' || is_readable($tmpName) === false) {
			return null;
		}

		$content = file_get_contents($tmpName);
		if ($content === false) {
			return null;
		}

		$fileName = basename((string)($uploaded['name'] ?? self::FALLBACK_NAME));
		if (in_array($fileName, self::UNUSABLE_NAMES, true) === true) {
			$fileName = self::FALLBACK_NAME;
		}

		return ['name' => $fileName, 'content' => $content];
	}//end read()

	/**
	 * Give the upload a name the case does not already carry, by appending
	 * `-2`, `-3` and so on before the extension.
	 *
	 * @param string             $fileName The sanitised upload name.
	 * @param iterable<mixed>    $existing The files the case already holds, as
	 *                                     PortalFileReader::listFiles()
	 *                                     returns them.
	 *
	 * @return string A name not present in $existing.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public static function uniqueName(string $fileName, iterable $existing): string {
		$taken = [];
		foreach ($existing as $file) {
			$name = ($file['name'] ?? null);
			if (is_string($name) === true && $name !== '') {
				$taken[] = $name;
			}
		}

		if (in_array($fileName, $taken, true) === false) {
			return $fileName;
		}

		$extension = pathinfo($fileName, PATHINFO_EXTENSION);
		$stem = pathinfo($fileName, PATHINFO_FILENAME);
		$suffix = '';
		if ($extension !== '') {
			$suffix = '.'.$extension;
		}

		$counter = 2;
		while (in_array($stem.'-'.$counter.$suffix, $taken, true) === true) {
			$counter++;
		}

		return $stem.'-'.$counter.$suffix;
	}//end uniqueName()
}//end class
