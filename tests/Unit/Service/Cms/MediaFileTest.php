<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\MediaFile;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\TestCase;

/**
 * site-page-seo-history-and-media T07 (REQ-SPH-004, REQ-SPH-005): the public
 * reach a published item of the serving portal and nothing else, and the
 * newest attached file is the one served, so a replaced file keeps the id.
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */
class MediaFileTest extends TestCase {

	private const PORTAL = ['slug' => 'gemeente', 'title' => 'Gemeente'];

	private const ITEM = ['id' => 'm1', 'title' => 'Stadhuis', 'alt' => 'Het stadhuis', 'kind' => 'image'];

	public function testThePublishedItemsNewestFileIsStreamed(): void {
		$stream = $this->createMock(StreamResponse::class);
		$files  = $this->files(listing: [['id' => 11, 'name' => 'old.jpg'], ['id' => 42, 'name' => 'new.jpg'], ['id' => 17, 'name' => 'mid.jpg']]);
		$files->expects($this->once())->method('streamFile')->with('portaliq', 'media', 'm1', '42')->willReturn($stream);

		$this->assertSame($stream, (new MediaFile($this->reader(item: self::ITEM), $files))->stream(portal: self::PORTAL, id: 'm1'));
	}//end testThePublishedItemsNewestFileIsStreamed()

	public function testADraftOrAnotherPortalsItemIsNotFound(): void {
		$files = $this->files(listing: [['id' => 1, 'name' => 'a.jpg']]);
		$files->expects($this->never())->method('streamFile');

		$this->assertNull((new MediaFile($this->reader(item: null), $files))->stream(portal: self::PORTAL, id: 'm1'));
	}//end testADraftOrAnotherPortalsItemIsNotFound()

	public function testAnItemWithoutAFileIsNotFound(): void {
		$files = $this->files(listing: []);
		$files->expects($this->never())->method('streamFile');

		$this->assertNull((new MediaFile($this->reader(item: self::ITEM), $files))->stream(portal: self::PORTAL, id: 'm1'));
	}//end testAnItemWithoutAFileIsNotFound()

	public function testAPortalBehindSignInServesNoItem(): void {
		$files = $this->files(listing: [['id' => 1, 'name' => 'a.jpg']]);
		$files->expects($this->never())->method('streamFile');
		$gated = self::PORTAL + ['authentication' => ['modes' => ['digid']]];

		$this->assertNull((new MediaFile($this->reader(item: self::ITEM), $files))->stream(portal: $gated, id: 'm1'));
	}//end testAPortalBehindSignInServesNoItem()

	/**
	 * A reader answering one item for (gemeente, m1).
	 *
	 * @param array|null $item The item, or null.
	 *
	 * @return MediaLibraryReader
	 */
	private function reader(?array $item): MediaLibraryReader {
		$reader = $this->getMockBuilder(MediaLibraryReader::class)->disableOriginalConstructor()->onlyMethods(['item'])->getMock();
		$reader->method('item')->willReturnCallback(
			static fn (string $portal, string $id) => ($portal === 'gemeente' && $id === 'm1') ? $item : null
		);

		return $reader;
	}//end reader()

	/**
	 * A file reader listing the item's attached files.
	 *
	 * @param array<array> $listing The files.
	 *
	 * @return PortalFileReader&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function files(array $listing): PortalFileReader {
		$files = $this->getMockBuilder(PortalFileReader::class)->disableOriginalConstructor()->onlyMethods(['listFiles', 'streamFile'])->getMock();
		$files->method('listFiles')->with('portaliq', 'media', 'm1')->willReturn($listing);

		return $files;
	}//end files()
}//end class
