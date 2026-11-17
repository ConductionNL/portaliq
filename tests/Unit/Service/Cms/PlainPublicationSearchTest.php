<?php

/**
 * PlainPublicationSearch: the GET form, the results, the total and the pages
 * of the plain publication search, and the honest sentence when the
 * publications cannot be read (site-honest-without-javascript REQ-SHJ-003,
 * REQ-SHJ-005).
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainAddress;
use OCA\Portaliq\Service\Cms\PlainDates;
use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainPublicationSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainPublicationSearch::class)]
#[UsesClass(PlainAddress::class)]
#[UsesClass(PlainDates::class)]
class PlainPublicationSearchTest extends TestCase {

	/**
	 * The form submits to `/site/plain` with the route, the portal and the
	 * term in a labelled `_search` field, and a result links to its plain
	 * detail page under the widget's detail route.
	 *
	 * @return void
	 */
	public function testTheFormSubmitsToThePlainRoute(): void {
		$reader = $this->reader();
		$reader->expects($this->once())->method('search')
			->with(PlainPublicationSearch::DEFAULT_ENDPOINT, 'afval', 1, 10)
			->willReturn(['state' => 'ok', 'total' => 1, 'results' => [
				['id' => 'p 1', 'title' => 'Woo-besluit afvalinzameling 2026', 'date' => '2026-03-01', 'summary' => 'Over de inzameling.'],
			]]);

		$block = $this->search(reader: $reader)->build(
			['inputLabel' => 'Zoek een publicatie', 'detailRoute' => '/woo/publicatie/'],
			'/zoeken',
			'afval',
			1,
			new PlainAddress('/index.php/apps/portaliq/site', 'demo'),
			'nl'
		);

		$this->assertSame('search', $block['kind']);
		$this->assertSame('/index.php/apps/portaliq/site/plain', $block['action']);
		$this->assertSame('/zoeken', $block['route']);
		$this->assertSame('demo', $block['portal']);
		$this->assertSame('Zoek een publicatie', $block['label']);
		$this->assertSame('Search', $block['submit']);
		$this->assertSame('afval', $block['query']);
		$this->assertSame('ok', $block['state']);
		$this->assertSame('1 result', $block['totalText']);
		$this->assertSame([
			[
				'title' => 'Woo-besluit afvalinzameling 2026',
				'href' => '/index.php/apps/portaliq/site/plain?route=/woo/publicatie/p%201&portal=demo',
				'date' => '1 maart 2026',
				'summary' => 'Over de inzameling.',
			],
		], $block['results']);
		$this->assertSame('', $block['previous']);
		$this->assertSame('', $block['next']);
		$this->assertSame('/index.php/apps/portaliq/site?route=/zoeken&portal=demo&_search=afval', $block['fullUrl']);
	}//end testTheFormSubmitsToThePlainRoute()

	/**
	 * Page 2 of 25 results at 10 a page: the total, the page asked for, and
	 * links to pages 1 and 3.
	 *
	 * @return void
	 */
	public function testPageTwoOfTwentyFive(): void {
		$rows = [];
		for ($index = 11; $index <= 20; $index++) {
			$rows[] = ['id' => 'p' . $index, 'title' => 'Publicatie ' . $index, 'date' => '', 'summary' => ''];
		}

		$reader = $this->reader();
		$reader->expects($this->once())->method('search')
			->with(PlainPublicationSearch::DEFAULT_ENDPOINT, 'afval', 2, 10)
			->willReturn(['state' => 'ok', 'total' => 25, 'results' => $rows]);

		$block = $this->search(reader: $reader)->build([], '/zoeken', 'afval', 2, new PlainAddress('/index.php/apps/portaliq/site', ''), 'nl');

		$this->assertSame(25, $block['total']);
		$this->assertSame('25 results', $block['totalText']);
		$this->assertSame('Publicatie 11', $block['results'][0]['title']);
		$this->assertSame('Publicatie 20', $block['results'][9]['title']);
		$this->assertCount(10, $block['results']);
		$this->assertSame(2, $block['page']);
		$this->assertSame(3, $block['pages']);
		$this->assertSame('Page 2 of 3', $block['pageText']);
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=/zoeken&_search=afval', $block['previous']);
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=/zoeken&_search=afval&_page=3', $block['next']);
		$this->assertSame('', $block['empty']);
	}//end testPageTwoOfTwentyFive()

	/**
	 * An unreadable endpoint and a foreign one say the publications cannot be
	 * shown; neither carries a total, a result or a "nothing found" text. A
	 * real zero does say nothing matched.
	 *
	 * @return void
	 */
	public function testUnavailableIsNotNothingFound(): void {
		$reader = $this->reader();
		$reader->expects($this->exactly(2))->method('search')->willReturnOnConsecutiveCalls(
			['state' => 'unavailable', 'total' => 0, 'results' => []],
			['state' => 'ok', 'total' => 0, 'results' => []]
		);
		$search = $this->search(reader: $reader);
		$address = new PlainAddress('/index.php/apps/portaliq/site', '');

		$down = $search->build([], '/zoeken', 'afval', 1, $address, 'nl');
		$foreign = $search->build(['endpoint' => 'https://elders.example/api/publications'], '/zoeken', 'afval', 1, $address, 'nl');
		$none = $search->build([], '/zoeken', 'afval', 1, $address, 'nl');

		foreach ([$down, $foreign] as $block) {
			$this->assertSame('unavailable', $block['state']);
			$this->assertSame('The publications cannot be shown here right now.', $block['sentence']);
			$this->assertSame([], $block['results']);
			$this->assertArrayNotHasKey('total', $block);
			$this->assertArrayNotHasKey('totalText', $block);
			$this->assertArrayNotHasKey('empty', $block);
			$this->assertSame('/index.php/apps/portaliq/site?route=/zoeken&_search=afval', $block['fullUrl']);
		}

		$this->assertSame('ok', $none['state']);
		$this->assertSame(0, $none['total']);
		$this->assertSame('No publications match this search.', $none['empty']);
	}//end testUnavailableIsNotNothingFound()

	/**
	 * The reader double; `isLocal` keeps its real rule.
	 *
	 * @return PlainPublicationReader&MockObject
	 */
	private function reader(): PlainPublicationReader {
		$reader = $this->getMockBuilder(PlainPublicationReader::class)->disableOriginalConstructor()->onlyMethods(['search'])->getMock();

		return $reader;
	}//end reader()

	/**
	 * The search under test.
	 *
	 * @param PlainPublicationReader $reader The reader.
	 *
	 * @return PlainPublicationSearch
	 */
	private function search(PlainPublicationReader $reader): PlainPublicationSearch {
		return new PlainPublicationSearch($reader, PlainTestWords::for($this), new PlainDates());
	}//end search()
}//end class
