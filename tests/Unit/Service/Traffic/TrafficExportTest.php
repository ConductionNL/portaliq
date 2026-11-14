<?php

/**
 * TrafficExport: the CSV and JSON export of daily traffic records and the
 * name of the file they download as.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Traffic
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficExport;
use PHPUnit\Framework\TestCase;

/**
 * The traffic export.
 */
class TrafficExportTest extends TestCase {
	/**
	 * The CSV lines of an export, without the trailing line break.
	 *
	 * @param array<int, array<string, mixed>> $records The records.
	 *
	 * @return array<int, string>
	 */
	private function csvLines(array $records): array {
		$csv = (new TrafficExport())->csv(records: $records);
		$this->assertStringEndsWith(suffix: "\r\n", string: $csv);
		return explode("\r\n", substr($csv, 0, -2));
	}//end csvLines()

	/**
	 * Without records the export is the header line alone.
	 *
	 * @return void
	 */
	public function testAnEmptyExportIsTheHeader(): void {
		$this->assertSame(expected: [implode(',', TrafficExport::COLUMNS)], actual: $this->csvLines(records: []));
	}//end testAnEmptyExportIsTheHeader()

	/**
	 * A record fills the columns in their fixed order and leaves missing ones empty.
	 *
	 * @return void
	 */
	public function testARecordFillsTheColumnsInOrder(): void {
		$lines = $this->csvLines(
			records: [
				[
					'visitors'   => 12,
					'portal'     => 'mijn',
					'date'       => '2026-11-13',
					'pageViews'  => 40,
					'bounceRate' => 0.25,
					'unknown'    => 'dropped',
				],
			]
		);
		$this->assertSame(expected: 'mijn,2026-11-13,,40,,12,,,,,,0.25,,,', actual: $lines[1]);
	}//end testARecordFillsTheColumnsInOrder()

	/**
	 * Booleans export as 1 and 0; nested values and nulls as empty cells.
	 *
	 * @return void
	 */
	public function testBooleansAndNestedValues(): void {
		$lines  = $this->csvLines(records: [['portal' => true, 'date' => false, 'segment' => ['x'], 'pageViews' => null]]);
		$fields = explode(',', $lines[1]);
		$this->assertSame(expected: ['1', '0', '', ''], actual: array_slice($fields, 0, 4));
		$this->assertCount(expectedCount: count(TrafficExport::COLUMNS), haystack: $fields);
	}//end testBooleansAndNestedValues()

	/**
	 * Commas, quotes and line breaks are quoted, with quotes doubled.
	 *
	 * @return void
	 */
	public function testSpecialCharactersAreQuoted(): void {
		$csv = (new TrafficExport())->csv(records: [['portal' => 'a,b', 'date' => 'say "hi"', 'segment' => "two\nlines"]]);
		$this->assertStringContainsString(needle: "\"a,b\",\"say \"\"hi\"\"\",\"two\nlines\",", haystack: $csv);
	}//end testSpecialCharactersAreQuoted()

	/**
	 * The zero-result searches are one cell: each term with its count.
	 *
	 * @return void
	 */
	public function testZeroResultSearchesAreOneCell(): void {
		$terms = [['term' => 'paspoort', 'count' => 3], ['term' => 'afval, grof', 'count' => '2'], ['count' => 9], 'junk', ['term' => 'x']];
		$lines = $this->csvLines(records: [['zeroResultSearches' => $terms]]);
		$this->assertStringEndsWith(suffix: ',"paspoort (3); afval, grof (2); x (0)"', string: $lines[1]);
		$this->assertStringEndsWith(suffix: ',', string: $this->csvLines(records: [['zeroResultSearches' => 'paspoort']])[1]);
	}//end testZeroResultSearchesAreOneCell()

	/**
	 * Each record is its own line.
	 *
	 * @return void
	 */
	public function testEachRecordIsALine(): void {
		$lines = $this->csvLines(records: [['portal' => 'a'], ['portal' => 'b'], ['portal' => 'c']]);
		$this->assertCount(expectedCount: 4, haystack: $lines);
		$this->assertSame(expected: ['a', 'b', 'c'], actual: array_map(static fn (string $line): string => explode(',', $line)[0], array_slice($lines, 1)));
	}//end testEachRecordIsALine()

	/**
	 * The JSON export drops the object metadata and keeps slashes and accents readable.
	 *
	 * @return void
	 */
	public function testTheJsonExportDropsTheObjectMetadata(): void {
		$json    = (new TrafficExport())->json(records: [['@self' => ['id' => 'x'], 'portal' => 'mijn/zaken', 'segment' => 'één']]);
		$decoded = json_decode($json, true);
		$this->assertSame(expected: [['portal' => 'mijn/zaken', 'segment' => 'één']], actual: $decoded);
		$this->assertStringContainsString(needle: 'mijn/zaken', haystack: $json);
		$this->assertStringContainsString(needle: 'één', haystack: $json);
		$this->assertSame(expected: '[]', actual: (new TrafficExport())->json(records: []));
	}//end testTheJsonExportDropsTheObjectMetadata()

	/**
	 * The file name joins its parts and replaces anything unsafe with a dash.
	 *
	 * @return void
	 */
	public function testTheFileNameIsSafe(): void {
		$export = new TrafficExport();
		$this->assertSame(
			expected: 'traffic-mijn-2026-11-01-2026-11-30.csv',
			actual: $export->fileName(portal: 'mijn', from: '2026-11-01', to: '2026-11-30', segment: '', format: 'csv')
		);
		$this->assertSame(
			expected: 'traffic-mijn-2026-11-01-2026-11-30-mobile.json',
			actual: $export->fileName(portal: 'mijn', from: '2026-11-01', to: '2026-11-30', segment: 'mobile', format: 'json')
		);
		$this->assertSame(
			expected: 'traffic-..-..-etc-passwd-a-b-c--x-y.csv',
			actual: $export->fileName(portal: '../../etc/passwd', from: 'a b', to: 'c"', segment: 'x/y', format: 'csv')
		);
	}//end testTheFileNameIsSafe()
}//end class
