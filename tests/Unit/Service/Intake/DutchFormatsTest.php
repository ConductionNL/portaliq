<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DutchFormats;
use PHPUnit\Framework\TestCase;

/**
 * data-lookups-and-checks-in-forms T02: each Dutch format accepts its valid
 * fixtures, stores them normalised, and refuses its invalid ones. The same
 * fixtures drive the site's formats.js.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
 */
class DutchFormatsTest extends TestCase {
	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function validCases(): array {
		$out = [];
		foreach (json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/dutch-formats.json'), true) as $format => $cases) {
			foreach ($cases['valid'] as [$input, $stored]) {
				$out[$format . ' ' . $input] = [$format, $input, $stored];
			}
		}

		return $out;
	}//end validCases()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalidCases(): array {
		$out = [];
		foreach (json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/dutch-formats.json'), true) as $format => $cases) {
			foreach ($cases['invalid'] as $input) {
				$out[$format . ' "' . $input . '"'] = [$format, $input];
			}
		}

		return $out;
	}//end invalidCases()

	/**
	 * @dataProvider validCases
	 */
	public function testAValidValueIsStoredNormalised(string $format, string $input, string $stored): void {
		$this->assertSame($stored, (new DutchFormats())->normalise($format, $input));
	}//end testAValidValueIsStoredNormalised()

	/**
	 * @dataProvider invalidCases
	 */
	public function testAnInvalidValueIsRefused(string $format, string $input): void {
		$this->assertNull((new DutchFormats())->normalise($format, $input));
	}//end testAnInvalidValueIsRefused()

	public function testEveryFormatHasFixtures(): void {
		$fixtures = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/dutch-formats.json'), true);
		$this->assertEqualsCanonicalizing(DutchFormats::FORMATS, array_keys($fixtures));
	}//end testEveryFormatHasFixtures()

	public function testAnUnknownFormatChecksNothing(): void {
		$formats = new DutchFormats();
		$this->assertFalse($formats->knows('colour'));
		$this->assertNull($formats->normalise('colour', 'red'));
	}//end testAnUnknownFormatChecksNothing()
}
