<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PublicIndexItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Holding a public index answer to its fixed shape.
 */
#[CoversClass(PublicIndexItems::class)]
#[UsesClass(\OCA\Portaliq\Contribution\PublicIndexText::class)]
class PublicIndexItemsTest extends TestCase {
	/**
	 * Every entry is kept, trimmed or dropped exactly as recorded, in order and once per id.
	 *
	 * @return void
	 */
	public function testEveryEntryIsAsRecorded(): void {
		$entries = [
			['id' => 'a', 'type' => 'event', 'kind' => 'Event', 'title' => 'One', 'summary' => ' A summary ', 'note' => 'Full', 'noteTone' => 'warning', 'badge' => 'New', 'dateLabel' => 'Soon', 'slug' => 'one-1', 'category' => 'Fun', 'cells' => ['x' => 'y'], 'date' => '2026-01-02', 'endDate' => '2026-01-02T10:00:00Z', 'meta' => ['m1', 'm2'], 'facets' => ['Year' => '2026', 'Tags' => ['a', 'b']], 'href' => '/x/y'],
			['id' => 'a', 'type' => 'event', 'kind' => 'Event', 'title' => 'Duplicate'],
			['id' => 'b', 'type' => 'news', 'kind' => 'News', 'title' => 'Two', 'note' => 'Hm', 'noteTone' => 'loud', 'slug' => 'Bad Slug', 'date' => '2026-13-45', 'endDate' => 'tomorrow', 'href' => '//evil'],
			['id' => 'c', 'type' => 'news', 'kind' => 'News', 'title' => 'Three', 'noteTone' => 'positive', 'href' => 'https://example.org/p'],
			['id' => 'd', 'type' => 'news', 'kind' => 'News', 'title' => 'Four', 'href' => 'javascript:x'],
			['id' => 'e', 'type' => '1bad', 'kind' => 'K', 'title' => 'T'],
			['id' => 'f', 'type' => 5, 'kind' => 'K', 'title' => 'T'],
			['id' => 'g', 'kind' => 'K', 'title' => 'T'],
			['id' => 'h', 'type' => 'news', 'title' => 'T'],
			['id' => 'i', 'type' => 'news', 'kind' => 'K'],
			['type' => 'news', 'kind' => 'K', 'title' => 'T'],
			['id' => '', 'type' => 'news', 'kind' => 'K', 'title' => 'T'],
			'not an entry',
			null,
			['id' => 'j', 'type' => 'aB3', 'kind' => 'K', 'title' => 'T', 'summary' => 5, 'meta' => 'x', 'facets' => 'x', 'cells' => 'x'],
		];

		$expected = '[{"id":"app:a","type":"event","kind":"Event","title":"One","slug":"one-1","category":"Fun","cells":{"x":"y"},"summary":"A summary","note":"Full","badge":"New","dateLabel":"Soon","noteTone":"warning","date":"2026-01-02","endDate":"2026-01-02T10:00:00Z","meta":["m1","m2"],"facets":{"Year":["2026"],"Tags":["a","b"]},"href":"\/x\/y"},{"id":"app:b","type":"news","kind":"News","title":"Two","note":"Hm","noteTone":"neutral"},{"id":"app:c","type":"news","kind":"News","title":"Three","href":"https:\/\/example.org\/p"},{"id":"app:d","type":"news","kind":"News","title":"Four"},{"id":"app:j","type":"aB3","kind":"K","title":"T","meta":["x"]}]';
		$this->assertSame($expected, json_encode((new PublicIndexItems())->items(appId: 'app', entries: $entries)));
	}//end testEveryEntryIsAsRecorded()

	/**
	 * No list is no items; a list is cut at the most items and read by position.
	 *
	 * @return void
	 */
	public function testTheAnswerIsCutAtTheMostItems(): void {
		$items = new PublicIndexItems();
		$this->assertSame([], $items->items(appId: 'app', entries: 'x'));

		$many = array_map(fn (int $n): array => ['id' => "n$n", 'type' => 't', 'kind' => 'K', 'title' => 'T'], range(1, 502));
		$kept = $items->items(appId: 'app', entries: $many);
		$this->assertCount(500, $kept);
		$this->assertSame('app:n500', $kept[499]['id']);

		$this->assertSame(
			[['id' => 'app:z', 'type' => 't', 'kind' => 'K', 'title' => 'T']],
			$items->items(appId: 'app', entries: ['k1' => ['id' => 'z', 'type' => 't', 'kind' => 'K', 'title' => 'T']])
		);
	}//end testTheAnswerIsCutAtTheMostItems()
}//end class
