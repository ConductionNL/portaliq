<?php

/**
 * PlainPublicRecords: a contributed record list, and one record, on the plain
 * page for a reader without JavaScript (site-member-voting-record-and-
 * confidential-papers REQ-SCR-005, REQ-SCR-006).
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PublicRecordReaderInterface;
use OCA\Portaliq\Service\Cms\PlainAddress;
use OCA\Portaliq\Service\Cms\PlainContext;
use OCA\Portaliq\Service\Cms\PlainPublicRecords;
use OCA\Portaliq\Service\Cms\PlainQuery;
use OCA\Portaliq\Service\Cms\PlainRecordPager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainPublicRecords::class)]
#[UsesClass(PlainAddress::class)]
#[UsesClass(PlainContext::class)]
#[UsesClass(PlainRecordPager::class)]
#[UsesClass(PlainQuery::class)]
class PlainPublicRecordsTest extends TestCase {

	/**
	 * The widget's props on the council page.
	 *
	 * @var array<string, mixed>
	 */
	private const PROPS = [
		'app' => 'decidiq',
		'list' => 'memberVotingRecords',
		'searchLabel' => 'Zoek een raadslid',
		'backText' => 'Alle raadsleden',
		'recordHeading' => 'Stemgedrag van {title}',
		'countText' => '{count} stemmingen',
	];

	/**
	 * The list's entries.
	 *
	 * @var list<array<string, string>>
	 */
	private const ENTRIES = [
		['id' => 'm1', 'title' => 'Sanne Mulder', 'subtitle' => 'Groen Links'],
		['id' => 'm2', 'title' => 'Joost van Dalen', 'subtitle' => 'VVD'],
	];

	/**
	 * Without a record the block is the search form and one link per entry
	 * to the same plain page with `record=<id>`, the subtitle under it.
	 *
	 * @return void
	 */
	public function testTheListLinksEveryEntryToItsRecord(): void {
		$block = $this->records()->build(self::PROPS, $this->context());

		$this->assertSame('records', $block['kind']);
		$this->assertSame('list', $block['mode']);
		$this->assertSame('ok', $block['state']);
		$this->assertSame('Zoek een raadslid', $block['label']);
		$this->assertSame('/index.php/apps/portaliq/site/plain', $block['action']);
		$this->assertSame('/raad', $block['route']);
		$this->assertSame('demo', $block['portal']);
		$this->assertSame([
			['label' => 'Sanne Mulder', 'href' => '/index.php/apps/portaliq/site/plain?route=/raad&portal=demo&record=m1', 'description' => 'Groen Links'],
			['label' => 'Joost van Dalen', 'href' => '/index.php/apps/portaliq/site/plain?route=/raad&portal=demo&record=m2', 'description' => 'VVD'],
		], $block['links']);
		$this->assertSame('', $block['empty']);
	}//end testTheListLinksEveryEntryToItsRecord()

	/**
	 * The search narrows on the title and the subtitle, without regard to
	 * case; a search that matches nothing says so.
	 *
	 * @return void
	 */
	public function testTheSearchNarrowsOnTitleAndSubtitle(): void {
		$byParty = $this->records()->build(self::PROPS, $this->context(query: 'vvd'));
		$none = $this->records()->build(self::PROPS, $this->context(query: 'niemand'));

		$this->assertSame(['Joost van Dalen'], array_column($byParty['links'], 'label'));
		$this->assertSame('vvd', $byParty['query']);
		$this->assertSame([], $none['links']);
		$this->assertSame('Nothing matches this search.', $none['empty']);
	}//end testTheSearchNarrowsOnTitleAndSubtitle()

	/**
	 * A record shows the page's heading sentence, the figures, the rows in
	 * column order with the subject linked (a site path through the plain
	 * page, an http(s) address as it is, anything else not at all), the
	 * count line, the note, the back link and the page's links.
	 *
	 * @return void
	 */
	public function testARecordRendersAsTheBoardDrawsIt(): void {
		$props = self::PROPS + ['links' => [
			['label' => 'Raadsinformatie', 'href' => '/bestuur'],
			['label' => 'Elders', 'href' => 'https://example.nl/x'],
			['label' => 'Kwaad', 'href' => 'javascript:alert(1)'],
		]];
		$record = [
			'title' => 'Sanne Mulder',
			'subtitle' => 'Groen Links',
			'note' => 'Alleen openbare stemmingen.',
			'summary' => [['label' => 'Voor', 'value' => '12', 'detail' => 'aan 20 stemmingen'], ['value' => 'zonder label']],
			'columns' => [['key' => 'date', 'label' => 'Datum'], ['key' => 'subject', 'label' => 'Onderwerp'], ['key' => 'choice', 'label' => 'Stem']],
			'rows' => [
				['date' => '1 oktober 2026', 'subject' => 'Groen dak op het stadhuis', 'choice' => 'Voor', 'subjectUrl' => '/publicatie/p1'],
				['date' => '2 oktober 2026', 'subject' => 'Begroting', 'choice' => 'Tegen', 'subjectUrl' => 'https://decidiq.example/b'],
				['date' => '3 oktober 2026', 'subject' => 'Motie', 'choice' => 'Onthouden'],
			],
		];

		$block = $this->records(record: $record)->build($props, $this->context(record: 'm1'));

		$this->assertSame('record', $block['mode']);
		$this->assertSame('ok', $block['state']);
		$this->assertSame('Stemgedrag van Sanne Mulder', $block['heading']);
		$this->assertSame('Groen Links', $block['subtitle']);
		$this->assertSame([['label' => 'Voor', 'value' => '12', 'detail' => 'aan 20 stemmingen']], $block['figures']);
		$this->assertSame(['Datum', 'Onderwerp', 'Stem'], $block['columns']);
		$this->assertSame([
			['text' => '1 oktober 2026', 'href' => ''],
			['text' => 'Groen dak op het stadhuis', 'href' => '/index.php/apps/portaliq/site/plain?route=/publicatie/p1&portal=demo'],
			['text' => 'Voor', 'href' => ''],
		], $block['rows'][0]);
		$this->assertSame('https://decidiq.example/b', $block['rows'][1][1]['href']);
		$this->assertSame('', $block['rows'][2][1]['href']);
		$this->assertSame('3 stemmingen', $block['countText']);
		$this->assertSame('Alleen openbare stemmingen.', $block['note']);
		$this->assertSame('Alle raadsleden', $block['backLabel']);
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=/raad&portal=demo', $block['backHref']);
		$this->assertSame(['Raadsinformatie', 'Elders'], array_column($block['links'], 'label'));
		$this->assertSame('', $block['previous']);
		$this->assertSame('', $block['next']);
		$this->assertSame('', $block['rangeText']);
		$this->assertSame('/index.php/apps/portaliq/site?route=/raad&portal=demo&record=m1', $block['fullUrl']);
	}//end testARecordRendersAsTheBoardDrawsIt()

	/**
	 * With a page size the rows show one page at a time; previous and next
	 * keep the record, a page past the end shows the last one.
	 *
	 * @return void
	 */
	public function testARecordPagesItsRows(): void {
		$rows = [];
		for ($i = 1; $i <= 12; $i++) {
			$rows[] = ['n' => (string)$i];
		}

		$record = ['title' => 'Joost van Dalen', 'columns' => [['key' => 'n', 'label' => 'Nr']], 'rows' => $rows];
		$props = ['pageSize' => 5, 'countText' => '{from} tot {to} van {count}'] + self::PROPS;

		$second = $this->records(record: $record)->build($props, $this->context(record: 'm2', page: 2));
		$beyond = $this->records(record: $record)->build($props, $this->context(record: 'm2', page: 9));

		$this->assertSame(['6', '7', '8', '9', '10'], array_map(static fn (array $row): string => $row[0]['text'], $second['rows']));
		$this->assertSame('6 tot 10 van 12', $second['countText']);
		$this->assertSame('6 to 10 of 12', $second['rangeText']);
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=/raad&portal=demo&record=m2', $second['previous']);
		$this->assertSame('/index.php/apps/portaliq/site/plain?route=/raad&portal=demo&record=m2&_page=3', $second['next']);
		$this->assertSame(['11', '12'], array_map(static fn (array $row): string => $row[0]['text'], $beyond['rows']));
		$this->assertSame('', $beyond['next']);
		$this->assertSame('Stemgedrag van Joost van Dalen', $second['heading']);
	}//end testARecordPagesItsRows()

	/**
	 * A record the list does not hold, and a list that is gone, say the
	 * overview no longer exists; the record keeps its link back.
	 *
	 * @return void
	 */
	public function testAStaleRecordAndAGoneListSaySo(): void {
		$stale = $this->records(record: null)->build(self::PROPS, $this->context(record: 'weg'));
		$gone = $this->records(entries: null)->build(self::PROPS, $this->context());

		$this->assertSame('gone', $stale['state']);
		$this->assertSame('This overview no longer exists.', $stale['sentence']);
		$this->assertSame('Alle raadsleden', $stale['backLabel']);
		$this->assertArrayNotHasKey('rows', $stale);
		$this->assertSame('gone', $gone['state']);
		$this->assertArrayNotHasKey('links', $gone);
	}//end testAStaleRecordAndAGoneListSaySo()

	/**
	 * A widget that names no app or no list renders nothing here, so the
	 * page says it needs JavaScript; the reader is not asked.
	 *
	 * @return void
	 */
	public function testAWidgetWithoutAppOrListIsNull(): void {
		$reader = $this->createMock(PublicRecordReaderInterface::class);
		$reader->expects($this->never())->method('entries');
		$reader->expects($this->never())->method('record');
		$records = new PlainPublicRecords($reader, PlainTestWords::for($this));

		$this->assertNull($records->build(['list' => 'x'], $this->context()));
		$this->assertNull($records->build(['app' => 'decidiq', 'list' => ['x']], $this->context()));
	}//end testAWidgetWithoutAppOrListIsNull()

	/**
	 * The block over a reader that answers the given list and record for
	 * decidiq's member voting records only.
	 *
	 * @param list<array<string, string>>|null $entries The list, or null when gone.
	 * @param array<string, mixed>|null        $record  The record, or null when not listed.
	 *
	 * @return PlainPublicRecords
	 */
	private function records(?array $entries = self::ENTRIES, ?array $record = null): PlainPublicRecords {
		$reader = $this->createMock(PublicRecordReaderInterface::class);
		$reader->method('entries')->willReturnCallback(
			function (string $app, string $list) use ($entries): ?array {
				$this->assertSame(['decidiq', 'memberVotingRecords'], [$app, $list]);

				return $entries;
			}
		);
		$reader->method('record')->willReturnCallback(
			function (string $app, string $list, string $id) use ($record): ?array {
				$this->assertSame(['decidiq', 'memberVotingRecords'], [$app, $list]);

				return $record;
			}
		);

		return new PlainPublicRecords($reader, PlainTestWords::for($this));
	}//end records()

	/**
	 * A plain request on the council page.
	 *
	 * @param string $query  The `_search`.
	 * @param string $record The `record`.
	 * @param int    $page   The `_page`.
	 *
	 * @return PlainContext
	 */
	private function context(string $query = '', string $record = '', int $page = 1): PlainContext {
		return new PlainContext(
			route: '/raad',
			routeParam: '',
			asked: new PlainQuery(query: $query, page: $page, record: $record),
			address: new PlainAddress('/index.php/apps/portaliq/site', 'demo'),
			locale: 'nl'
		);
	}//end context()
}//end class
