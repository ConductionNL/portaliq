<?php

/**
 * Unit tests for ListBlockNormaliser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\BoardKeys;
use OCA\Portaliq\Contribution\CollectionProjection;
use OCA\Portaliq\Contribution\ListBlockNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The inbox, cases and record blocks a contribution declares, kept only when they point somewhere real.
 */
#[CoversClass(ListBlockNormaliser::class)]
#[UsesClass(BoardKeys::class)]
#[UsesClass(CollectionProjection::class)]
class ListBlockNormaliserTest extends TestCase {

	/**
	 * The normaliser under test.
	 *
	 * @var ListBlockNormaliser
	 */
	private ListBlockNormaliser $blocks;

	/**
	 * The collections the contribution declares.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $collections = [
		['id' => 'messages', 'kind' => 'inbox'],
		['id' => 'zaken', 'kind' => 'cases'],
		['id' => 'aanvraag', 'kind' => 'records', 'steps' => [], 'documents' => [], 'fields' => ['title', 'number']],
		['id' => 'bare', 'kind' => 'records'],
	];


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->blocks = new ListBlockNormaliser();
	}//end setUp()


	/**
	 * An inbox needs no collection, but a named one must be an inbox; the record field must be a path.
	 *
	 * @return void
	 */
	public function testInboxBlock(): void {
		$this->assertSame(['type' => 'inbox', 'limit' => 5, 'display' => 'list'], $this->blocks->inboxBlock(['limit' => 5, 'display' => 'list'], $this->collections));
		$this->assertSame(
			['type' => 'inbox', 'collection' => 'messages', 'recordField' => 'case.id', 'label' => 'Berichten'],
			$this->blocks->inboxBlock(['collection' => 'messages', 'recordField' => 'case.id', 'label' => ' Berichten '], $this->collections)
		);
		$this->assertNull($this->blocks->inboxBlock(['collection' => 'zaken'], $this->collections));
		$this->assertNull($this->blocks->inboxBlock(['collection' => null], $this->collections));
		$this->assertSame(['type' => 'inbox'], $this->blocks->inboxBlock(['recordField' => '1case'], $this->collections));
	}//end testInboxBlock()


	/**
	 * A cases block needs a cases collection; `open` is carried only as true.
	 *
	 * @return void
	 */
	public function testCasesBlock(): void {
		$this->assertSame(
			['type' => 'cases', 'collection' => 'zaken', 'open' => true, 'display' => 'compact'],
			$this->blocks->casesBlock(['collection' => 'zaken', 'open' => true, 'display' => 'compact'], $this->collections)
		);
		$this->assertSame(['type' => 'cases', 'collection' => 'zaken'], $this->blocks->casesBlock(['collection' => 'zaken', 'open' => 'yes'], $this->collections));
		$this->assertNull($this->blocks->casesBlock(['collection' => 'messages'], $this->collections));
		$this->assertNull($this->blocks->casesBlock(['collection' => 'missing'], $this->collections));
	}//end testCasesBlock()


	/**
	 * Limits and labels are kept within their bounds only.
	 *
	 * @return void
	 */
	public function testLimitsAndLabelsAreBounded(): void {
		$entry = fn (array $extra): array => $this->blocks->casesBlock(['collection' => 'zaken'] + $extra, $this->collections);

		$this->assertSame(50, $entry(['limit' => 50])['limit']);
		$this->assertArrayNotHasKey('limit', $entry(['limit' => 51]));
		$this->assertArrayNotHasKey('limit', $entry(['limit' => 0]));
		$this->assertArrayNotHasKey('limit', $entry(['limit' => '5']));
		$this->assertArrayNotHasKey('label', $entry(['label' => '  ']));
		$this->assertArrayNotHasKey('label', $entry(['label' => str_repeat('x', 121)]));
	}//end testLimitsAndLabelsAreBounded()


	/**
	 * A record block needs a record type the collection declares.
	 *
	 * @return void
	 */
	public function testRecordBlockNeedsATypeTheCollectionDeclares(): void {
		$this->assertSame(['type' => 'steps', 'collection' => 'aanvraag', 'display' => 'bars'], $this->blocks->recordBlock('steps', ['collection' => 'aanvraag', 'display' => 'bars'], $this->collections));
		$this->assertSame(['type' => 'steps', 'collection' => 'aanvraag'], $this->blocks->recordBlock('steps', ['collection' => 'aanvraag', 'display' => 'list'], $this->collections));
		$this->assertNull($this->blocks->recordBlock('timeline', ['collection' => 'aanvraag'], $this->collections));
		$this->assertNull($this->blocks->recordBlock('steps', ['collection' => 'bare'], $this->collections));
		$this->assertNull($this->blocks->recordBlock('cases', ['collection' => 'aanvraag'], $this->collections));
	}//end testRecordBlockNeedsATypeTheCollectionDeclares()


	/**
	 * A documents block for each record keeps only the title fields the collection projects.
	 *
	 * @return void
	 */
	public function testDocumentsForEachRecord(): void {
		$this->assertSame(
			['type' => 'documents', 'collection' => 'aanvraag', 'upload' => true, 'eachRecord' => true, 'titleFields' => ['title'], 'subtitleFields' => ['number']],
			$this->blocks->recordBlock(
				'documents',
				['collection' => 'aanvraag', 'upload' => true, 'eachRecord' => true, 'titleFields' => ['title', 'secret'], 'subtitleFields' => ['number', 4]],
				$this->collections
			)
		);
		$this->assertSame(
			['type' => 'documents', 'collection' => 'aanvraag'],
			$this->blocks->recordBlock('documents', ['collection' => 'aanvraag', 'titleFields' => ['title']], $this->collections)
		);
	}//end testDocumentsForEachRecord()


	/**
	 * Off the record page, record blocks stay only for the page's own collections or when they span every record.
	 *
	 * @return void
	 */
	public function testRecordPageOnly(): void {
		$blocks = [
			['type' => 'text'],
			['type' => 'steps', 'collection' => 'aanvraag'],
			['type' => 'documents', 'collection' => 'other'],
			['type' => 'documents', 'collection' => 'other', 'eachRecord' => true],
			['type' => 'timeline', 'collection' => 'other'],
		];

		$this->assertSame([$blocks[0], $blocks[1], $blocks[3]], $this->blocks->recordPageOnly($blocks, ['aanvraag']));
	}//end testRecordPageOnly()
}//end class
