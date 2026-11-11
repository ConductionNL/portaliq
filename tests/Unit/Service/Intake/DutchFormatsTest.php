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

	/**
	 * Every value of every format is kept, normalised or refused exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryFormatAndValueIsAsRecorded(): void {
		$formats = new DutchFormats();
		$cases   = [
			['bsn', ' 111222333 ', '111222333'],
			['bsn', '123456782', '123456782'],
			['bsn', '000000000', null],
			['bsn', '12345678', null],
			['bsn', '1234567890', null],
			['bsn', '12345678a', null],
			['bsn', '111222334', null],
			['iban', 'NL91 ABNA 0417 1643 00', 'NL91ABNA0417164300'],
			['iban', 'nl91abna0417164300', 'NL91ABNA0417164300'],
			['iban', 'NL91ABNA041716430', null],
			['iban', 'DE89370400440532013000', 'DE89370400440532013000'],
			['iban', 'DE89370400440532013001', null],
			['iban', 'GB82WEST12345698765432', 'GB82WEST12345698765432'],
			['iban', 'XX', null],
			['iban', '1234ABNA0417164300', null],
			['nl-licence-plate', 'AB-12-34', 'AB1234'],
			['nl-licence-plate', '12-AB-34', '12AB34'],
			['nl-licence-plate', 'ab1234', 'AB1234'],
			['nl-licence-plate', 'GBB-12-X', 'GBB12X'],
			['nl-licence-plate', 'AAA-12-X', null],
			['nl-licence-plate', 'A-123-BC', null],
			['nl-licence-plate', '1-ABC-23', null],
			['nl-licence-plate', 'K-123-BB', 'K123BB'],
			['nl-licence-plate', 'AB12345', null],
			['nl-licence-plate', '123456', null],
			['nl-licence-plate', 'ABCDEF', null],
			['nl-licence-plate', 'XXQ-12-B', null],
			['phone-nl', '06-12345678', '0612345678'],
			['phone-nl', '+31 6 12345678', '+31612345678'],
			['phone-nl', '0031612345678', '0031612345678'],
			['phone-nl', '0612345', null],
			['phone-nl', '(020) 123 4567', '0201234567'],
			['phone-nl', '+316123456789', null],
			['phone-nl', '0012345678', null],
			['phone-international', '+44 20 7946 0958', '+442079460958'],
			['phone-international', '+1234567', '+1234567'],
			['phone-international', '+123456', null],
			['phone-international', '0044207946', null],
			['phone-international', '+0123456789', null],
			['postcode', '1234 AB', '1234 AB'],
			['postcode', '1234ab', '1234 AB'],
			['postcode', '1234  AB', null],
			['postcode', '0123 AB', null],
			['postcode', '1234 SA', null],
			['postcode', '1234 sd', null],
			['postcode', '1234 SS', null],
			['postcode', '1234 ST', '1234 ST'],
			['postcode', '12345 AB', null],
			['kvk', '12345678', '12345678'],
			['kvk', ' 12345678 ', '12345678'],
			['kvk', '1234567', null],
			['kvk', '1234567a', null],
			['kvk-branch', '123456789012', '123456789012'],
			['kvk-branch', '12345678901', null],
			['kvk-branch', '1234567890123', null],
			['unknown', 'x', null],
			['unknown', '', null],
			['', 'x', null],
		];
		foreach ($cases as [$format, $value, $expected]) {
			$this->assertSame($expected, $formats->normalise(format: $format, value: $value), $format . ' ' . $value);
		}
	}//end testEveryFormatAndValueIsAsRecorded()
}
