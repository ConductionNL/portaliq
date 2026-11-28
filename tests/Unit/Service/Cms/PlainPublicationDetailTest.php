<?php

/**
 * PlainPublicationDetail: a publication, its facts by name and its documents
 * as download links, and one answer for missing and withheld
 * (site-honest-without-javascript REQ-SHJ-004).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.3
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainCategoryNames;
use OCA\Portaliq\Service\Cms\PlainDates;
use OCA\Portaliq\Service\Cms\PlainPublicationDetail;
use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainPublicationSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainPublicationDetail::class)]
#[UsesClass(PlainCategoryNames::class)]
#[UsesClass(PlainDates::class)]
#[UsesClass(\OCA\Portaliq\Service\Cms\PlainPublicationReader::class)]
class PlainPublicationDetailTest extends TestCase {

	/**
	 * Title, summary, date, category and themes by name, and each document
	 * with its name, type, readable size and address.
	 *
	 * @return void
	 */
	public function testThePublicationAndItsDocuments(): void {
		$reader = $this->getMockBuilder(PlainPublicationReader::class)->disableOriginalConstructor()->onlyMethods(['publication', 'themeName'])->getMock();
		$reader->expects($this->once())->method('publication')->with(PlainPublicationSearch::DEFAULT_ENDPOINT, 'p1')->willReturn([
			'state' => 'ok',
			'publication' => [
				'id' => 'p1',
				'title' => 'Woo-besluit afvalinzameling 2026',
				'summary' => 'Het besluit over de inzameling.',
				'publicationDate' => '2026-03-01',
				'wooCategory' => 'infocat014',
				'themes' => [['id' => 't1', 'title' => 'Afval'], 't2', ['id' => 't3']],
			],
			'documents' => [
				['name' => 'besluit.pdf', 'type' => 'PDF', 'size' => 2400000, 'href' => '/download/1'],
				['name' => 'bijlage', 'type' => '', 'size' => 0, 'href' => ''],
			],
		]);
		$reader->expects($this->exactly(2))->method('themeName')->willReturnCallback(
			static fn (string $endpoint, string $id): string => ($endpoint === PlainPublicationDetail::DEFAULT_THEMES_ENDPOINT && $id === 't2' ? 'Wonen' : '')
		);

		$block = $this->detail(reader: $reader)->build([], 'p1', '/site?route=/publicatie/p1', 'nl');

		$this->assertSame('ok', $block['state']);
		$this->assertSame('Woo-besluit afvalinzameling 2026', $block['title']);
		$this->assertSame('Het besluit over de inzameling.', $block['summary']);
		$this->assertSame([
			['label' => 'Publication date', 'value' => '1 maart 2026'],
			['label' => 'Information category', 'value' => 'Woo-verzoeken en -besluiten'],
			['label' => 'Themes', 'value' => 'Afval, Wonen'],
		], $block['facts']);
		$this->assertSame([
			['name' => 'besluit.pdf', 'type' => 'PDF', 'size' => '2,4 MB', 'href' => '/download/1'],
			['name' => 'bijlage', 'type' => '', 'size' => '', 'href' => ''],
		], $block['documents']);
		$this->assertSame('/site?route=/publicatie/p1', $block['fullUrl']);
	}//end testThePublicationAndItsDocuments()

	/**
	 * A missing and a withheld publication give the same block, word for
	 * word; an unreadable one says so instead, and a foreign endpoint is
	 * never asked.
	 *
	 * @return void
	 */
	public function testMissingAndWithheldAnswerTheSame404(): void {
		$reader = $this->getMockBuilder(PlainPublicationReader::class)->disableOriginalConstructor()->onlyMethods(['publication'])->getMock();
		$reader->expects($this->exactly(3))->method('publication')->willReturnCallback(
			static fn (string $endpoint, string $id): array => match ($id) {
				'missing', 'withheld' => ['state' => 'not-found'],
				default => ['state' => 'unavailable'],
			}
		);
		$detail = $this->detail(reader: $reader);

		$missing = $detail->build([], 'missing', '/site?route=/publicatie/x', 'nl');
		$withheld = $detail->build([], 'withheld', '/site?route=/publicatie/x', 'nl');
		$down = $detail->build([], 'down', '/site?route=/publicatie/x', 'nl');
		$foreign = $detail->build(['endpoint' => 'https://elders.example/api'], 'p1', '/site?route=/publicatie/x', 'nl');

		$this->assertSame('not-found', $missing['state']);
		$this->assertSame($missing, $withheld);
		$this->assertSame('Publication not found', $missing['title']);
		$this->assertSame('unavailable', $down['state']);
		$this->assertSame('The publications cannot be shown here right now.', $down['sentence']);
		$this->assertSame($down, $foreign);
	}//end testMissingAndWithheldAnswerTheSame404()

	/**
	 * The detail under test.
	 *
	 * @param PlainPublicationReader $reader The reader.
	 *
	 * @return PlainPublicationDetail
	 */
	private function detail(PlainPublicationReader $reader): PlainPublicationDetail {
		return new PlainPublicationDetail($reader, PlainTestWords::for($this), new PlainDates(), new PlainCategoryNames());
	}//end detail()
}//end class
