<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\MediaUsage;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use PHPUnit\Framework\TestCase;

/**
 * A media item knows how many records of its portal use it.
 *
 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
 */
class MediaUsageTest extends TestCase {
	/**
	 * A hero image, a share image, a markdown link and the logo address all
	 * count; a record naming an item twice counts once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
	 */
	public function testEveryKindOfReferenceCountsOncePerRecord(): void {
		$records = [
			['heroImage' => 'media:m-1', 'seoImage' => 'media:m-1'],
			['body' => [['widgets' => [['content' => ['markdown' => 'Zie ![kaart](media:m-2) en [pdf](media:m-1)']]]]]],
			['logo' => '/apps/portaliq/api/content/media/m-3?portal=zuiddrecht'],
			['title' => 'Nothing here'],
		];

		$this->assertSame(expected: ['m-1' => 2, 'm-2' => 1, 'm-3' => 1], actual: MediaUsage::count(records: $records));
	}//end testEveryKindOfReferenceCountsOncePerRecord()

	/**
	 * The recount writes only the counts that changed, 0 for an unused item,
	 * and reads each portal's own records.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
	 */
	public function testTheRecountWritesWhatChanged(): void {
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(value: [['slug' => 'zuiddrecht', 'logo' => 'media:m-3']]);

		$rows = [
			'page' => [['portal' => 'zuiddrecht', 'heroImage' => 'media:m-1']],
			'newsItem' => [['portal' => 'zuiddrecht', 'photoRefs' => ['media:m-1']]],
			'media' => [
				['uuid' => 'm-1', 'portal' => 'zuiddrecht', 'usedOn' => 2],
				['uuid' => 'm-2', 'portal' => 'zuiddrecht', 'usedOn' => 4],
				['uuid' => 'm-3', 'portal' => 'zuiddrecht'],
			],
		];
		$asked  = [];
		$reader = $this->createMock(originalClassName: PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef) use ($rows, &$asked): array {
				$asked[] = [$schema, $scopeField, $subjectRef];
				return $rows[$schema];
			}
		);

		$written = [];
		$writer  = $this->createMock(originalClassName: PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (...$args) use (&$written): array {
				$written[$args[5]] = $args[6];
				return $args[6];
			}
		);

		$usage = new MediaUsage(portals: $portals, reader: $reader, writer: $writer);

		$this->assertSame(expected: 2, actual: $usage->refresh());
		$this->assertSame(expected: ['m-2' => ['usedOn' => 0], 'm-3' => ['usedOn' => 1]], actual: $written);
		$this->assertContains(needle: ['media', 'portal', 'zuiddrecht'], haystack: $asked);
	}//end testTheRecountWritesWhatChanged()
}//end class
