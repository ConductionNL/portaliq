<?php

/**
 * Portaliq suggestion word list
 *
 * The words a did-you-mean suggestion may propose, per portal: every word of
 * the titles and summaries the anonymous public search returns, with how
 * often it occurs. Built by a daily job and kept in this app's data folder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use OCP\Files\SimpleFS\ISimpleFolder;
use Throwable;

/**
 * Builds, stores and reads the per-portal word list.
 *
 * NOTHING NON-PUBLIC MAY REACH THE LIST. A suggestion is a word printed to an
 * anonymous visitor, so a word from a draft would publish the draft one word
 * at a time. The list is read only through the anonymous search, and a row
 * that still says it is unpublished or withdrawn is skipped as well.
 */
class SuggestionWordList {

	/**
	 * The folder in this app's data.
	 */
	private const FOLDER = 'search-suggestions';

	/**
	 * Rows read per page.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Pages read at most, so one large catalogue cannot hold the job.
	 */
	private const MAX_PAGES = 50;

	/**
	 * Shorter words are not worth correcting.
	 */
	private const MIN_WORD_LENGTH = 3;

	/**
	 * Constructor.
	 *
	 * @param AnonymousPublicationSearch $search  The anonymous public search.
	 * @param IAppData                   $appData This app's data folder.
	 */
	public function __construct(
		private readonly AnonymousPublicationSearch $search,
		private readonly IAppData $appData,
	) {
	}//end __construct()

	/**
	 * Build the list for one portal from the anonymous search, and store it.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, int> The words and how often each occurs.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function rebuild(string $portal): array {
		$words = $this->build();
		$this->store(portal: $portal, words: $words);

		return $words;
	}//end rebuild()

	/**
	 * The words of every public publication.
	 *
	 * @return array<string, int> The words and how often each occurs.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function build(): array {
		$words = [];
		$read  = 0;
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$answer = $this->search->search(term: '', page: $page, limit: self::PAGE_SIZE);
			foreach ($answer['results'] as $row) {
				if ($this->isPublicPublication(row: $row) === true) {
					$words = $this->count(words: $words, text: $this->textOf(row: $row));
				}
			}

			$read += count($answer['results']);
			if ($answer['results'] === [] || $read >= $answer['total']) {
				break;
			}
		}

		ksort($words);
		return $words;
	}//end build()

	/**
	 * The stored list of one portal; empty when none was built yet.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, int> The words and how often each occurs.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function load(string $portal): array {
		try {
			$content = $this->folder()->getFile($this->fileName(portal: $portal))->getContent();
		} catch (Throwable) {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$words = [];
		foreach ($decoded as $word => $count) {
			if (is_string($word) === true && is_int($count) === true) {
				$words[$word] = $count;
			}
		}

		return $words;
	}//end load()

	/**
	 * The words of a text, lower case, letters only.
	 *
	 * @param string $text The text.
	 *
	 * @return list<string> The words, in order, repeats kept.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public static function wordsOf(string $text): array {
		$parts = preg_split('/[^\p{L}]+/u', mb_strtolower($text));
		if ($parts === false) {
			return [];
		}

		return array_values(
			array_filter(
				$parts,
				static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH
			)
		);
	}//end wordsOf()

	/**
	 * Whether a row is a publication the public may read.
	 *
	 * Documents are left out: the list is made of publication titles and
	 * summaries, and a file name is not a word a visitor meant to type.
	 *
	 * @param array<string, mixed> $row One row of the anonymous search.
	 *
	 * @return bool
	 */
	private function isPublicPublication(array $row): bool {
		if (($row['resultType'] ?? '') === 'document') {
			return false;
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			return true;
		}

		if (array_key_exists('published', $self) === true && empty($self['published']) === true) {
			return false;
		}

		return empty($self['depublished']);
	}//end isPublicPublication()

	/**
	 * The title and summary of a row, the same fields the search block shows.
	 *
	 * @param array<string, mixed> $row One row.
	 *
	 * @return string The text.
	 */
	private function textOf(array $row): string {
		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$parts = [
			$row['name'] ?? ($self['name'] ?? ($self['title'] ?? '')),
			$self['summary'] ?? ($row['description'] ?? ''),
		];

		return implode(' ', array_filter($parts, 'is_string'));
	}//end textOf()

	/**
	 * Add the words of a text to the counts.
	 *
	 * @param array<string, int> $words The counts so far.
	 * @param string             $text  The text.
	 *
	 * @return array<string, int> The counts.
	 */
	private function count(array $words, string $text): array {
		foreach (self::wordsOf(text: $text) as $word) {
			$words[$word] = (($words[$word] ?? 0) + 1);
		}

		return $words;
	}//end count()

	/**
	 * Store the list of one portal.
	 *
	 * @param string             $portal The portal slug.
	 * @param array<string, int> $words  The list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function store(string $portal, array $words): void {
		$folder = $this->folder();
		$name   = $this->fileName(portal: $portal);
		$json   = (string)json_encode($words, JSON_UNESCAPED_UNICODE);
		try {
			$folder->getFile($name)->putContent($json);
		} catch (NotFoundException) {
			$folder->newFile($name, $json);
		}
	}//end store()

	/**
	 * The list's folder, created on first use.
	 *
	 * @return ISimpleFolder
	 */
	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}//end folder()

	/**
	 * The file of one portal; the slug is reduced to safe characters.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return string The file name.
	 */
	private function fileName(string $portal): string {
		$safe = preg_replace('/[^a-z0-9-]/', '', strtolower($portal));
		if ($safe === null || $safe === '') {
			$safe = 'default';
		}

		return $safe.'.json';
	}//end fileName()
}//end class
