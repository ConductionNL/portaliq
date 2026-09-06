<?php

/**
 * Unit tests for GzipExtractor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic\Geo;

use OCA\Portaliq\Service\Traffic\Geo\GzipExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Both archive shapes the providers ship, built here byte by byte so the
 * test owns the format it claims to read.
 */
class GzipExtractorTest extends TestCase {

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()


	/**
	 * A fresh temporary path.
	 *
	 * @return string The path.
	 */
	private function temp(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'gz');
		$this->files[] = $path;

		return $path;
	}//end temp()


	/**
	 * One ustar member: header plus padded content.
	 *
	 * @param string $name    The member name.
	 * @param string $content The content.
	 * @param string $type    The type flag.
	 *
	 * @return string The bytes.
	 */
	public static function tarMember(string $name, string $content, string $type = '0'): string {
		$header = str_pad($name, 100, "\0")
			. str_pad('0000644', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
			. str_pad(sprintf('%011o', strlen($content)), 12, "\0")
			. str_pad(sprintf('%011o', 0), 12, "\0")
			. '        '
			. $type
			. str_pad('', 100, "\0")
			. "ustar\0" . '00'
			. str_pad('', 32, "\0") . str_pad('', 32, "\0") . str_pad('', 8, "\0") . str_pad('', 8, "\0")
			. str_pad('', 155, "\0");
		$header = str_pad($header, 512, "\0");
		$checksum = array_sum(array_map('ord', str_split($header)));
		$header = substr_replace($header, str_pad(sprintf('%06o', $checksum), 7, "\0") . ' ', 148, 8);
		$padded = str_pad($content, (int)(ceil(strlen($content) / 512) * 512), "\0");

		return $header . $padded;
	}//end tarMember()


	/**
	 * A bare gzip decompresses to the target, and a non-gzip source is
	 * refused.
	 *
	 * @return void
	 */
	public function testABareGzipDecompresses(): void {
		$payload = str_repeat('mmdb-bytes-', 20000);
		$source = $this->temp();
		file_put_contents($source, (string)gzencode($payload));
		$target = $this->temp();

		(new GzipExtractor())->extract(source: $source, target: $target);
		$this->assertSame($payload, file_get_contents($target));

		file_put_contents($source, 'plain text, not gzip');
		$this->expectException(RuntimeException::class);
		(new GzipExtractor())->extract(source: $source, target: $target);
	}//end testABareGzipDecompresses()


	/**
	 * The `.mmdb` member of a gzipped tar is extracted, skipping a
	 * directory entry and another file before it.
	 *
	 * @return void
	 */
	public function testTheMmdbMemberOfATarballIsExtracted(): void {
		$database = str_repeat("\xAB\xCD", 1500);
		$tar = self::tarMember('GeoLite2-City_20260901/', '', '5')
			. self::tarMember('GeoLite2-City_20260901/LICENSE.txt', 'licence text')
			. self::tarMember('GeoLite2-City_20260901/GeoLite2-City.mmdb', $database)
			. str_repeat("\0", 1024);
		$source = $this->temp();
		file_put_contents($source, (string)gzencode($tar));
		$target = $this->temp();

		$member = (new GzipExtractor())->extractTarMember(source: $source, suffix: '.mmdb', target: $target);
		$this->assertSame('GeoLite2-City_20260901/GeoLite2-City.mmdb', $member);
		$this->assertSame($database, file_get_contents($target));
	}//end testTheMmdbMemberOfATarballIsExtracted()


	/**
	 * A tarball without the member is refused by name.
	 *
	 * @return void
	 */
	public function testATarballWithoutTheMemberIsRefused(): void {
		$source = $this->temp();
		file_put_contents($source, (string)gzencode(self::tarMember('README', 'nothing here') . str_repeat("\0", 1024)));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('.mmdb');
		(new GzipExtractor())->extractTarMember(source: $source, suffix: '.mmdb', target: $this->temp());
	}//end testATarballWithoutTheMemberIsRefused()
}//end class
