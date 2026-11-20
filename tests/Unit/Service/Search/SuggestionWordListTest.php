<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\Search\AnonymousPublicationSearch;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

/**
 * search-sort-by-relevance REQ-SSR-005: the word list is made of public
 * titles and summaries only, and is kept per portal.
 *
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t08
 */
class SuggestionWordListTest extends TestCase {

	/**
	 * Files written to the stand-in folder, by name.
	 *
	 * @var array<string, string>
	 */
	private array $files = [];

	/**
	 * A word list over a search double with the anonymous search's own signature.
	 *
	 * @param list<array{results: list<array<string, mixed>>, total: int}> $pages The pages the search answers, in order.
	 *
	 * @return SuggestionWordList
	 */
	private function wordList(array $pages): SuggestionWordList {
		$search = $this->createMock(AnonymousPublicationSearch::class);
		$search->method('search')->willReturnCallback(
			static function (string $term, int $page=1, int $limit=100) use ($pages): array {
				return ($pages[($page - 1)] ?? ['results' => [], 'total' => 0]);
			}
		);

		return new SuggestionWordList($search, $this->appData());
	}

	/**
	 * An app data folder that keeps files in memory.
	 *
	 * @return IAppData
	 */
	private function appData(): IAppData {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(
			function (string $name): ISimpleFile {
				if (isset($this->files[$name]) === false) {
					throw new NotFoundException($name);
				}

				return $this->file(name: $name);
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content=null): ISimpleFile {
				$this->files[$name] = (string)$content;
				return $this->file(name: $name);
			}
		);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		return $appData;
	}

	/**
	 * One in-memory file.
	 *
	 * @param string $name The file name.
	 *
	 * @return ISimpleFile
	 */
	private function file(string $name): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$name]);
		$file->method('putContent')->willReturnCallback(
			function ($content) use ($name): void {
				$this->files[$name] = (string)$content;
			}
		);

		return $file;
	}

	public function testADraftNeverReachesTheList(): void {
		$list = $this->wordList(
			pages: [[
				'results' => [
					['name' => 'Hondenbelasting 2026', '@self' => ['published' => '2026-01-02T00:00:00+00:00', 'summary' => 'Tarieven']],
					['name' => 'Reorganisatie geheim', '@self' => ['published' => null]],
					['name' => 'Ingetrokken besluit', '@self' => ['published' => '2026-01-02T00:00:00+00:00', 'depublished' => '2026-02-01T00:00:00+00:00']],
				],
				'total' => 3,
			]]
		);

		$words = $list->rebuild(portal: 'zuiddrecht');

		$this->assertSame(['hondenbelasting' => 1, 'tarieven' => 1], $words);
		$this->assertArrayNotHasKey('geheim', $words);
		$this->assertArrayNotHasKey('reorganisatie', $words);
		$this->assertArrayNotHasKey('ingetrokken', $words);
	}

	public function testTitlesAndSummariesAreCountedAndDocumentsLeftOut(): void {
		$list = $this->wordList(
			pages: [[
				'results' => [
					['name' => 'Terras vergunning', 'description' => 'Een vergunning voor een terras'],
					['@self' => ['title' => 'Vergunning evenementen', 'summary' => 'Aanvragen']],
					['name' => 'Rapport geheimhouding.pdf', 'resultType' => 'document'],
				],
				'total' => 3,
			]]
		);

		$this->assertSame(
			['aanvragen' => 1, 'een' => 2, 'evenementen' => 1, 'terras' => 2, 'vergunning' => 3, 'voor' => 1],
			$list->build()
		);
	}

	public function testEveryPageIsReadUntilTheTotal(): void {
		$list = $this->wordList(
			pages: [
				['results' => [['name' => 'Eerste']], 'total' => 2],
				['results' => [['name' => 'Tweede']], 'total' => 2],
				['results' => [['name' => 'Derde']], 'total' => 2],
			]
		);

		$this->assertSame(['eerste' => 1, 'tweede' => 1], $list->build());
	}

	public function testTheListIsStoredPerPortalAndReadBack(): void {
		$list = $this->wordList(pages: [['results' => [['name' => 'Parkeren']], 'total' => 1]]);

		$list->rebuild(portal: 'Zuid/drecht');

		$this->assertSame(['zuiddrecht.json'], array_keys($this->files));
		$this->assertSame(['parkeren' => 1], $list->load(portal: 'Zuid/drecht'));
		$this->assertSame([], $list->load(portal: 'noord'));
	}

	public function testAStoredListIsOverwritten(): void {
		$list = $this->wordList(pages: [['results' => [['name' => 'Nieuw']], 'total' => 1]]);
		$this->files['zuiddrecht.json'] = '{"oud":1}';

		$list->rebuild(portal: 'zuiddrecht');

		$this->assertSame(['nieuw' => 1], $list->load(portal: 'zuiddrecht'));
	}

	public function testAnUnreadableStoredListIsEmpty(): void {
		$list = $this->wordList(pages: []);
		$this->files['zuiddrecht.json'] = 'not json';

		$this->assertSame([], $list->load(portal: 'zuiddrecht'));
	}

	public function testWordsAreLowerCaseLettersOfThreeOrMore(): void {
		$this->assertSame(
			['café', 'één', 'wet', 'open', 'overheid'],
			SuggestionWordList::wordsOf(text: 'Café één, de Wet open-overheid 2026 a')
		);
	}
}
