<?php

/**
 * Tests for the pages that place a shared block (site-shared-page-blocks T06).
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
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Service\Cms\SharedBlockPlacements;
use OCA\Portaliq\Service\CmsRows;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Cms\SharedBlockPlacements
 * @uses   \OCA\Portaliq\Service\RowIdLookup
 */
class SharedBlockPlacementsTest extends TestCase {

	private const BLOCK = 'block-contact';

	/**
	 * @var PortalObjectReaderInterface&MockObject
	 */
	private $reader;

	/**
	 * @var CmsRows&MockObject
	 */
	private $rows;

	/**
	 * Set up the reader and the portal rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reader = $this->createMock(originalClassName: PortalObjectReaderInterface::class);
		$this->rows   = $this->createMock(originalClassName: CmsRows::class);
	}//end setUp()

	/**
	 * A grid body with the given widgets.
	 *
	 * @param array<int, array<string, mixed>> $widgets The widgets.
	 *
	 * @return array<string, mixed> The body.
	 */
	private static function grid(array $widgets): array {
		return ['type' => 'grid', 'widgets' => $widgets];
	}//end grid()

	/**
	 * A placement of a block.
	 *
	 * @param string $block The block id.
	 *
	 * @return array<string, mixed> The widget entry.
	 */
	private static function placement(string $block): array {
		return ['id' => 'w-' . $block, 'widgetKey' => 'sharedBlock', 'props' => ['block' => $block]];
	}//end placement()

	/**
	 * Configure the block read and the pages per portal.
	 *
	 * @param array<string, mixed>|null                        $block  The block row the user can read, or null.
	 * @param array<int, array<string, mixed>>                 $portals The portal rows of the organisation.
	 * @param array<string, array<int, array<string, mixed>>>  $pages  The page rows per portal slug.
	 *
	 * @return void
	 */
	private function given(?array $block, array $portals=[], array $pages=[]): void {
		$this->reader->method('readObjectAsUser')->willReturn($block);
		$this->rows->method('query')->willReturn($portals);
		$this->reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef): array => ($pages[$subjectRef] ?? [])
		);
	}//end given()

	/**
	 * The service under test.
	 *
	 * @return SharedBlockPlacements
	 */
	private function placements(): SharedBlockPlacements {
		return new SharedBlockPlacements(reader: $this->reader, rows: $this->rows);
	}//end placements()

	/**
	 * Every page of the organisation's portals whose published body places the block is listed, by portal and route.
	 *
	 * @return void
	 */
	public function testListsThePagesThatPlaceTheBlockOnEveryPortalOfItsOrganisation(): void {
		$this->given(
			block: ['id' => self::BLOCK, 'organisation' => 'gemeente-voorbeeld'],
			portals: [
				['slug' => 'inwoners', 'title' => 'Inwoners', 'organisation' => 'gemeente-voorbeeld'],
				['slug' => 'bedrijven', 'title' => 'Bedrijven', 'organisation' => 'gemeente-voorbeeld'],
			],
			pages: [
				'inwoners' => [
					['id' => 'p-contact', 'title' => 'Contact', 'route' => '/contact', 'status' => 'published', 'portal' => 'inwoners', 'body' => self::grid([self::placement(self::BLOCK)])],
					['id' => 'p-home', 'title' => 'Home', 'route' => '/', 'status' => 'published', 'portal' => 'inwoners', 'body' => self::grid([['widgetKey' => 'markdown'], self::placement(self::BLOCK)])],
					['id' => 'p-news', 'title' => 'Nieuws', 'route' => '/nieuws', 'status' => 'published', 'portal' => 'inwoners', 'body' => self::grid([['widgetKey' => 'markdown']])],
				],
				'bedrijven' => [
					['id' => 'b-home', 'title' => 'Home', 'route' => '/', 'status' => 'draft', 'portal' => 'bedrijven', 'body' => self::grid([self::placement(self::BLOCK)])],
				],
			]
		);

		$this->assertSame(
			expected: [
				['id' => 'b-home', 'title' => 'Home', 'route' => '/', 'status' => 'draft', 'portal' => 'bedrijven', 'portalTitle' => 'Bedrijven'],
				['id' => 'p-home', 'title' => 'Home', 'route' => '/', 'status' => 'published', 'portal' => 'inwoners', 'portalTitle' => 'Inwoners'],
				['id' => 'p-contact', 'title' => 'Contact', 'route' => '/contact', 'status' => 'published', 'portal' => 'inwoners', 'portalTitle' => 'Inwoners'],
			],
			actual: $this->placements()->pagesOf(blockId: self::BLOCK)
		);
	}//end testListsThePagesThatPlaceTheBlockOnEveryPortalOfItsOrganisation()

	/**
	 * The block is read as the user, and the portals are asked for by the block's organisation.
	 *
	 * @return void
	 */
	public function testTheBlockIsReadAsTheUserAndThePortalsByItsOrganisation(): void {
		$this->reader->expects($this->once())->method('readObjectAsUser')
			->with('portaliq', 'sharedBlock', self::BLOCK)
			->willReturn(['id' => self::BLOCK, 'organisation' => 'gemeente-voorbeeld']);
		$this->rows->expects($this->once())->method('query')
			->with('portal', ['organisation' => 'gemeente-voorbeeld'])
			->willReturn([['slug' => 'inwoners', 'organisation' => 'gemeente-voorbeeld']]);
		$this->reader->expects($this->once())->method('readCollection')
			->with('portaliq', 'page', 'portal', 'inwoners')
			->willReturn([]);

		$this->assertSame(expected: [], actual: $this->placements()->pagesOf(blockId: ' ' . self::BLOCK . ' '));
	}//end testTheBlockIsReadAsTheUserAndThePortalsByItsOrganisation()

	/**
	 * A block the user cannot read answers null and reads no page.
	 *
	 * @return void
	 */
	public function testABlockTheUserCannotReadAnswersNull(): void {
		$this->given(block: null);
		$this->rows->expects($this->never())->method('query');

		$this->assertNull(actual: $this->placements()->pagesOf(blockId: self::BLOCK));
	}//end testABlockTheUserCannotReadAnswersNull()

	/**
	 * A block without an organisation is placed nowhere, and no portal is asked for.
	 *
	 * @return void
	 */
	public function testABlockWithoutAnOrganisationIsPlacedNowhere(): void {
		$this->given(block: ['id' => self::BLOCK]);
		$this->rows->expects($this->never())->method('query');

		$this->assertSame(expected: [], actual: $this->placements()->pagesOf(blockId: self::BLOCK));
	}//end testABlockWithoutAnOrganisationIsPlacedNowhere()

	/**
	 * A portal of another organisation, and a page of another portal, are skipped even when the store returns them.
	 *
	 * @return void
	 */
	public function testRowsOutsideTheOrganisationOrPortalAreSkipped(): void {
		$this->given(
			block: ['id' => self::BLOCK, 'organisation' => 'gemeente-voorbeeld'],
			portals: [
				['slug' => 'noord', 'organisation' => 'gemeente-noord'],
				['slug' => 'inwoners', 'organisation' => 'gemeente-voorbeeld'],
				['slug' => '', 'organisation' => 'gemeente-voorbeeld'],
			],
			pages: [
				'noord' => [['id' => 'n-home', 'portal' => 'noord', 'body' => self::grid([self::placement(self::BLOCK)])]],
				'inwoners' => [
					['id' => 'stray', 'portal' => 'noord', 'body' => self::grid([self::placement(self::BLOCK)])],
					['id' => 'p-home', 'title' => 'Home', 'route' => '/', 'portal' => 'inwoners', 'body' => self::grid([self::placement(self::BLOCK)])],
				],
			]
		);

		$pages = $this->placements()->pagesOf(blockId: self::BLOCK);

		$this->assertSame(expected: ['p-home'], actual: array_column($pages, 'id'));
		$this->assertSame(expected: 'inwoners', actual: $pages[0]['portalTitle']);
	}//end testRowsOutsideTheOrganisationOrPortalAreSkipped()

	/**
	 * A placement that is only in the draft body is not listed: it is on no page yet.
	 *
	 * @return void
	 */
	public function testADraftPlacementIsNotListed(): void {
		$this->given(
			block: ['id' => self::BLOCK, 'organisation' => 'gemeente-voorbeeld'],
			portals: [['slug' => 'inwoners', 'organisation' => 'gemeente-voorbeeld']],
			pages: [
				'inwoners' => [
					['id' => 'p-home', 'portal' => 'inwoners', 'body' => self::grid([]), 'draftBody' => self::grid([self::placement(self::BLOCK)])],
				],
			]
		);

		$this->assertSame(expected: [], actual: $this->placements()->pagesOf(blockId: self::BLOCK));
	}//end testADraftPlacementIsNotListed()

	/**
	 * A page placing the block by its uuid is found when the block is asked for by its id.
	 *
	 * @return void
	 */
	public function testThePlacementMatchesTheBlocksUuidToo(): void {
		$this->given(
			block: ['id' => '42', 'uuid' => 'uuid-contact', 'organisation' => 'gemeente-voorbeeld'],
			portals: [['slug' => 'inwoners', 'organisation' => 'gemeente-voorbeeld']],
			pages: ['inwoners' => [['id' => 'p-home', 'portal' => 'inwoners', 'body' => self::grid([self::placement('uuid-contact')])]]]
		);

		$this->assertSame(expected: ['p-home'], actual: array_column($this->placements()->pagesOf(blockId: '42'), 'id'));
	}//end testThePlacementMatchesTheBlocksUuidToo()

	/**
	 * Only a `sharedBlock` widget naming one of the ids counts as a placement.
	 *
	 * @return void
	 */
	public function testOnlyASharedBlockWidgetNamingTheBlockPlacesIt(): void {
		$this->assertTrue(condition: SharedBlockPlacements::places(body: self::grid([self::placement('a')]), blockIds: ['a']));
		$this->assertTrue(condition: SharedBlockPlacements::places(body: self::grid([['widgetKey' => 'sharedBlock', 'props' => ['block' => ' a ']]]), blockIds: ['a']));
		$this->assertFalse(condition: SharedBlockPlacements::places(body: self::grid([self::placement('b')]), blockIds: ['a']));
		$this->assertFalse(condition: SharedBlockPlacements::places(body: self::grid([['widgetKey' => 'markdown', 'props' => ['block' => 'a']]]), blockIds: ['a']));
		$this->assertFalse(condition: SharedBlockPlacements::places(body: self::grid([['widgetKey' => 'sharedBlock', 'props' => []]]), blockIds: ['a', '']));
		$this->assertFalse(condition: SharedBlockPlacements::places(body: ['type' => 'markdown', 'content' => 'a'], blockIds: ['a']));
		$this->assertFalse(condition: SharedBlockPlacements::places(body: null, blockIds: ['a']));
		$this->assertFalse(condition: SharedBlockPlacements::places(body: self::grid(['not a widget']), blockIds: ['a']));
	}//end testOnlyASharedBlockWidgetNamingTheBlockPlacesIt()
}//end class
