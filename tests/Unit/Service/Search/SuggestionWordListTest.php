<?php

/**
 * Unit tests for the word list the "did you mean" correction reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/search-suggestions-while-typing/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\Search\PublicPublicationSearch;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Words come from published publications only, and the list is kept per portal.
 *
 * @covers \OCA\Portaliq\Service\Search\SuggestionWordList
 */
class SuggestionWordListTest extends TestCase {
	/**
	 * @return void
	 */
	public function testTokensAreLowerCaseLettersOfAtLeastThreeCharacters(): void {
		$this->assertSame(['afval', 'brengen', 'eén'], SuggestionWordList::tokens(text: 'Afval, brengen: 12 of EÉN!'));
		$this->assertSame([], SuggestionWordList::tokens(text: 'a 1 of'));
	}//end testTokensAreLowerCaseLettersOfAtLeastThreeCharacters()

	/**
	 * @return void
	 */
	public function testWordsCountPublishedRowsOnly(): void {
		$rows = [
			['name' => 'Afval brengen', 'summary' => 'Afval en grofvuil'],
			['title' => 'Grofvuil', 'description' => 'afhalen', '@self' => ['published' => '2026-01-01']],
			['name' => 'Verborgen', 'status' => 'Concept'],
			['name' => 'Ingetrokken', '@self' => ['published' => null]],
			['@self' => ['name' => 'Zelf', 'summary' => 'samenvatting']],
		];

		$this->assertSame(
			['afhalen' => 1, 'afval' => 2, 'brengen' => 1, 'grofvuil' => 2, 'samenvatting' => 1, 'zelf' => 1],
			SuggestionWordList::wordsOf(rows: $rows)
		);
	}//end testWordsCountPublishedRowsOnly()

	/**
	 * @return void
	 */
	public function testRebuildStoresTheListInANewFolderAndCountsTheWords(): void {
		$search = $this->createMock(PublicPublicationSearch::class);
		$search->method('rows')->willReturn([['name' => 'Afval brengen']]);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('newFile')->with('de_stad.json', '{"afval":1,"brengen":1}');
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException());
		$appData->expects($this->once())->method('newFolder')->with(SuggestionWordList::FOLDER)->willReturn($folder);

		$list = new SuggestionWordList($search, $appData, $this->createMock(LoggerInterface::class));

		$this->assertSame(2, $list->rebuild(portal: 'De Stad'));
	}//end testRebuildStoresTheListInANewFolderAndCountsTheWords()

	/**
	 * @return void
	 */
	public function testRebuildSurvivesAStoreThatCannotBeWritten(): void {
		$search = $this->createMock(PublicPublicationSearch::class);
		$search->method('rows')->willReturn([['name' => 'Afval']]);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame(1, (new SuggestionWordList($search, $appData, $logger))->rebuild(portal: 'a'));
	}//end testRebuildSurvivesAStoreThatCannotBeWritten()

	/**
	 * @return void
	 */
	public function testWordsReadTheStoredListAndAnythingElseIsEmpty(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnOnConsecutiveCalls('{"afval":"3","kaart":1}', 'not json');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->exactly(2))->method('getFile')->with('de_stad.json')->willReturn($file);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with(SuggestionWordList::FOLDER)->willReturn($folder);
		$list = new SuggestionWordList($this->createMock(PublicPublicationSearch::class), $appData, $this->createMock(LoggerInterface::class));

		$this->assertSame(['afval' => 3, 'kaart' => 1], $list->words(portal: 'De Stad'));
		$this->assertSame([], $list->words(portal: 'De Stad'));

		$missing = $this->createMock(IAppData::class);
		$missing->method('getFolder')->willThrowException(new NotFoundException());
		$empty = new SuggestionWordList($this->createMock(PublicPublicationSearch::class), $missing, $this->createMock(LoggerInterface::class));
		$this->assertSame([], $empty->words(portal: 'x'));
	}//end testWordsReadTheStoredListAndAnythingElseIsEmpty()
}//end class
