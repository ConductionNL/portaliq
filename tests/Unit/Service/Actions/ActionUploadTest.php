<?php

/**
 * ActionUpload and ThemeAppAsset: which uploaded files an action accepts,
 * judged by their bytes and not only their name, and which theme
 * stylesheets an app ships.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Actions;

use OCA\Portaliq\Service\Actions\ActionUpload;
use OCA\Portaliq\Service\ThemeAppAsset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The action upload check.
 */
class ActionUploadTest extends TestCase {
	/**
	 * Uploads and whether they are accepted.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function uploads(): array {
		return [
			'a pdf'                    => ['bewijs.pdf', "%PDF-1.7\n...", true],
			'a jpg'                    => ['foto.jpg', "\xFF\xD8\xFF\xE0rest", true],
			'a jpeg, upper case'       => ['FOTO.JPEG', "\xFF\xD8\xFF\xE1rest", true],
			'a png'                    => ['scan.png', "\x89PNG\r\n\x1A\nrest", true],
			'a pdf name, html bytes'   => ['bewijs.pdf', '<html><script>', false],
			'a png name, jpg bytes'    => ['scan.png', "\xFF\xD8\xFF\xE0", false],
			'a jpg name, pdf bytes'    => ['foto.jpg', '%PDF-1.7', false],
			'an unknown extension'     => ['bewijs.docx', "PK\x03\x04", false],
			'no extension'             => ['bewijs', '%PDF-1.7', false],
			'a double extension'       => ['bewijs.pdf.exe', '%PDF-1.7', false],
			'an svg'                   => ['logo.svg', '<svg/>', false],
			'empty content'            => ['bewijs.pdf', '', false],
			'a truncated signature'    => ['scan.png', "\x89PNG", false],
		];
	}//end uploads()

	/**
	 * A file is accepted when its extension is allowed and its bytes start as that type does.
	 *
	 * @param string $name The file name.
	 * @param string $content The file content.
	 * @param bool $accepted Whether it is accepted.
	 *
	 * @return void
	 */
	#[DataProvider('uploads')]
	public function testAnUploadIsJudgedByItsBytes(string $name, string $content, bool $accepted): void {
		$this->assertSame(expected: $accepted, actual: (new ActionUpload())->accepts(name: $name, content: $content));
	}//end testAnUploadIsJudgedByItsBytes()

	/**
	 * A file of exactly ten megabytes is accepted, one byte more is not.
	 *
	 * @return void
	 */
	public function testTheSizeLimitIsTenMegabytes(): void {
		$upload = new ActionUpload();
		$pdf    = '%PDF-'.str_repeat('x', ActionUpload::MAX_BYTES - 5);
		$this->assertSame(expected: ActionUpload::MAX_BYTES, actual: strlen($pdf));
		$this->assertTrue(condition: $upload->accepts(name: 'big.pdf', content: $pdf));
		$this->assertFalse(condition: $upload->accepts(name: 'big.pdf', content: $pdf.'x'));
	}//end testTheSizeLimitIsTenMegabytes()

	/**
	 * A theme stylesheet is named only when the app ships the file.
	 *
	 * @return void
	 */
	public function testAThemeStylesheetMustBeShipped(): void {
		$root = sys_get_temp_dir().'/portaliq-theme-'.bin2hex(random_bytes(4));
		mkdir($root.'/css', 0700, true);
		touch($root.'/css/nl-design.css');
		try {
			$asset = new ThemeAppAsset();
			$this->assertSame(expected: 'nl-design', actual: $asset->stylesheetIfShipped(root: $root, name: 'nl-design'));
			$this->assertNull(actual: $asset->stylesheetIfShipped(root: $root, name: 'missing'));
			$this->assertNull(actual: $asset->stylesheetIfShipped(root: null, name: 'nl-design'));
			$this->assertNull(actual: $asset->stylesheetIfShipped(root: $root.'/nowhere', name: 'nl-design'));
		} finally {
			unlink($root.'/css/nl-design.css');
			rmdir($root.'/css');
			rmdir($root);
		}
	}//end testAThemeStylesheetMustBeShipped()
}//end class
