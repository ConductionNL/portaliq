<?php

/**
 * Portaliq Citizen Document Upload
 *
 * Everything the citizen's document upload needs before it reaches the case:
 * reading the multipart body into bytes, and picking a name no document on the
 * case already uses.
 *
 * It lives beside the controller rather than inside it because both jobs are
 * about the file and neither is about the request's authorisation, and because
 * a controller that also owns them carries their whole decision tree.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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

namespace OCA\Portaliq\Contribution;

/**
 * Reads the citizen's upload and names it so it adds rather than replaces.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenDocumentUpload {

	/**
	 * Read the multipart upload into a {name, content} pair, or null when
	 * there is nothing usable. The client's path is never trusted.
	 *
	 * @param array<string, mixed>|null $uploaded The entry from IRequest::getUploadedFile().
	 *
	 * @return array{name: string, content: string}|null
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function read(?array $uploaded): ?array {
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

	/**
	 * A name no document already on the case uses, so an upload adds rather
	 * than replaces.
	 *
	 * @param array<int, array<string, mixed>> $existing The files already on the case.
	 * @param string                           $fileName The sanitised upload name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function uniqueName(array $existing, string $fileName): string {
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
			$suffix = '.' . $extension;
		}

		$counter = 2;
		while (in_array($stem . '-' . $counter . $suffix, $taken, true) === true) {
			$counter++;
		}

		return $stem . '-' . $counter . $suffix;
	}//end uniqueName()
}//end class
