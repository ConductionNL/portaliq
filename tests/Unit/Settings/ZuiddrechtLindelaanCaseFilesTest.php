<?php

/**
 * Example data for DqZaakDocumenten: the Lindelaan Woo case carries the seven
 * documents under review as its own files, the same files its information
 * objects hold, so the Files tab lists what the review counts.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-lindelaan-case-files/spec.md#requirement-the-lindelaan-case-carries-the-seven-documents-under-review
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The case files of woo-fietspad.
 *
 * @spec openspec/specs/example-set-lindelaan-case-files/spec.md#requirement-the-lindelaan-case-carries-the-seven-documents-under-review
 */
class ZuiddrechtLindelaanCaseFilesTest extends TestCase {

	/**
	 * The case's files are exactly the files of its seven information objects, each one present on disk.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheSevenDocuments(): void {
		$dir     = __DIR__.'/../../../lib/Settings/example-sets/';
		$set     = json_decode((string)file_get_contents($dir.'zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$documents = [];
		foreach ($objects as $key => $object) {
			// The concept decision letter is the case's own document, not one under review.
			if ($object['schema'] === 'informatieobject' && str_starts_with($key, 'woo-fietspad-') === true && $key !== 'woo-fietspad-besluit-concept') {
				$documents[] = $object['files'][0]['path'];
			}
		}

		$caseFiles = array_column($objects['woo-fietspad']['files'], 'path');
		$this->assertCount(expectedCount: 7, haystack: $caseFiles);
		$this->assertEqualsCanonicalizing(expected: $documents, actual: $caseFiles);
		foreach ($caseFiles as $path) {
			$this->assertFileExists(filename: $dir.$path);
		}
	}//end testTheCaseCarriesTheSevenDocuments()
}//end class
