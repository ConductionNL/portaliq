<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\Search\PublicPublicationSearch;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The word list is made of what the anonymous public search answers, titles
 * and summaries only (search-sort-by-relevance REQ-SSR-005).
 *
 * The real PublicPublicationSearch runs over an InstanceLoopback double whose
 * request() has the real signature; the double answers what the anonymous
 * endpoint answers, and records that no credential was sent.
 *
 * @covers \OCA\Portaliq\Service\Search\SuggestionWordList
 * @covers \OCA\Portaliq\Service\Search\PublicPublicationSearch
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SuggestionWordListTest extends TestCase {

	/**
	 * The requests the loopback received.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * What was stored, by file name.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	public function testADraftNeverReachesTheList(): void {
		$rows = [
			['name' => 'Hondenbelasting 2026', '@self' => ['summary' => 'Tarieven voor de hondenbelasting', 'published' => '2026-01-02']],
			['name' => 'Reorganisatie geheim', 'status' => 'draft', '@self' => ['summary' => 'Niet openbaar']],
			['name' => 'Concept nota', '@self' => ['published' => null, 'summary' => 'Vertrouwelijk stuk']],
		];
		$list = $this->list(rows: $rows);

		$this->assertSame(3, $list->rebuild(portal: 'gemeente'));
		$words = $list->words(portal: 'gemeente');

		$this->assertSame(['hondenbelasting' => 2, 'tarieven' => 1, 'voor' => 1], $words);
		$this->assertArrayNotHasKey('geheim', $words);
		$this->assertArrayNotHasKey('vertrouwelijk', $words);
		$this->assertArrayNotHasKey('de', $words, 'a word under three letters is never a correction');

		// Anonymous: the request carries no cookie, token or authorisation.
		[$path, $options] = $this->requests[0];
		$this->assertStringStartsWith(PublicPublicationSearch::ENDPOINT.'?', $path);
		$headers = array_change_key_case((array)($options['headers'] ?? []));
		$this->assertArrayNotHasKey('authorization', $headers);
		$this->assertArrayNotHasKey('cookie', $headers);
		$this->assertArrayNotHasKey('auth', $options);
	}//end testADraftNeverReachesTheList()

	public function testAnUnbuiltListIsEmpty(): void {
		$this->assertSame([], $this->list(rows: [])->words(portal: 'nergens'));
	}//end testAnUnbuiltListIsEmpty()

	public function testTheCountAsksFuzzyMatching(): void {
		$search = new PublicPublicationSearch($this->loopback(rows: [], total: 7), new NullLogger());

		$this->assertSame(7, $search->count(term: 'hondenbelasting'));
		$this->assertStringContainsString('_fuzzy=true', $this->requests[0][0]);
		$this->assertStringContainsString('_search=hondenbelasting', $this->requests[0][0]);
	}//end testTheCountAsksFuzzyMatching()

	/**
	 * The list over the real search, a loopback answering these rows and an
	 * in-memory app data folder.
	 *
	 * @param list<array<string, mixed>> $rows The anonymous search's rows.
	 *
	 * @return SuggestionWordList
	 */
	private function list(array $rows): SuggestionWordList {
		$search = new PublicPublicationSearch($this->loopback(rows: $rows, total: count($rows)), new NullLogger());

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) {
				$this->stored[$name] = (string)$content;
				return $this->createMock(ISimpleFile::class);
			}
		);
		$folder->method('getFile')->willReturnCallback(
			function (string $name) {
				if (isset($this->stored[$name]) === false) {
					throw new NotFoundException();
				}

				$file = $this->createMock(ISimpleFile::class);
				$file->method('getContent')->willReturn($this->stored[$name]);
				return $file;
			}
		);

		$built   = false;
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			static function () use (&$built, $folder) {
				if ($built === false) {
					throw new NotFoundException();
				}

				return $folder;
			}
		);
		$appData->method('newFolder')->willReturnCallback(
			static function () use (&$built, $folder) {
				$built = true;
				return $folder;
			}
		);

		return new SuggestionWordList($search, $appData, new NullLogger());
	}//end list()

	/**
	 * A loopback answering one page of rows.
	 *
	 * @param list<array<string, mixed>> $rows  The rows.
	 * @param int                        $total The total.
	 *
	 * @return InstanceLoopback
	 */
	private function loopback(array $rows, int $total): InstanceLoopback {
		$loopback = $this->getMockBuilder(InstanceLoopback::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
		$loopback->method('request')->willReturnCallback(
			function (string $method, string $path, array $options = []) use ($rows, $total): IResponse {
				$this->requests[] = [$path, $options];
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn((string)json_encode(['results' => $rows, 'total' => $total, 'pages' => 1]));
				return $response;
			}
		);

		return $loopback;
	}//end loopback()
}//end class
