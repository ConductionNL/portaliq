<?php

/**
 * Portaliq suggestion word list
 *
 * The words of the public publications' titles and summaries, per portal, that
 * a search correction may be picked from.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Search;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds, stores and reads the word list of one portal.
 *
 * Built only from what the anonymous public search answers (titles and
 * summaries), so a draft or a closed publication never lends a word. Stored as
 * JSON in this app's data folder, one file per portal; the daily
 * SuggestionWordListJob rebuilds it.
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SuggestionWordList {

	/**
	 * The app data folder holding the lists.
	 */
	public const FOLDER = 'search-words';

	/**
	 * Words shorter than this are never a correction.
	 */
	public const MIN_LENGTH = 3;

	/**
	 * Constructor.
	 *
	 * @param PublicPublicationSearch $search  The anonymous public search.
	 * @param IAppData                $appData This app's data folder.
	 * @param LoggerInterface         $logger  Logs a failed write.
	 */
	public function __construct(
		private readonly PublicPublicationSearch $search,
		private readonly IAppData $appData,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rebuild one portal's list from the public search, and store it.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return int How many distinct words the list holds.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function rebuild(string $portal): int {
		$words = self::wordsOf(rows: $this->search->rows());
		try {
			try {
				$folder = $this->appData->getFolder(self::FOLDER);
			} catch (NotFoundException) {
				$folder = $this->appData->newFolder(self::FOLDER);
			}

			$folder->newFile($this->fileName(portal: $portal), (string)json_encode($words));
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the search word list could not be stored', ['portal' => $portal, 'reason' => $e->getMessage()]);
		}

		return count($words);
	}//end rebuild()

	/**
	 * The stored list of one portal: word => how often it occurs.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function words(string $portal): array {
		try {
			$content = $this->appData->getFolder(self::FOLDER)->getFile($this->fileName(portal: $portal))->getContent();
		} catch (Throwable) {
			return [];
		}

		$words = json_decode($content, true);
		if (is_array($words) === false) {
			return [];
		}

		$list = [];
		foreach ($words as $word => $count) {
			$list[(string)$word] = (int)$count;
		}

		return $list;
	}//end words()

	/**
	 * The words of the rows' titles and summaries with their counts, lower case.
	 *
	 * A row that says it is not published is left out, on top of the public
	 * search leaving it out already.
	 *
	 * @param list<array<string, mixed>> $rows Rows of the public search.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public static function wordsOf(array $rows): array {
		$words = [];
		foreach ($rows as $row) {
			$self = (array)($row['@self'] ?? []);
			if (in_array(strtolower((string)($row['status'] ?? '')), ['draft', 'concept'], true) === true
				|| (array_key_exists('published', $self) === true && $self['published'] === null)
			) {
				continue;
			}

			$text = implode(
				' ',
				[
					(string)($row['name'] ?? $self['name'] ?? $row['title'] ?? ''),
					(string)($self['summary'] ?? $row['summary'] ?? $row['description'] ?? ''),
				]
			);
			foreach (self::tokens(text: $text) as $word) {
				$words[$word] = ($words[$word] ?? 0) + 1;
			}
		}

		ksort($words);

		return $words;
	}//end wordsOf()

	/**
	 * The words of a text: letters only, lower case, at least MIN_LENGTH long.
	 *
	 * @param string $text The text.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public static function tokens(string $text): array {
		$parts = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

		return array_values(
			array_filter(
				(array)$parts,
				static fn (string $word): bool => mb_strlen($word) >= self::MIN_LENGTH
			)
		);
	}//end tokens()

	/**
	 * The file of one portal's list.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return string
	 */
	private function fileName(string $portal): string {
		return preg_replace('/[^a-z0-9-]/', '_', strtolower($portal)).'.json';
	}//end fileName()
}//end class
