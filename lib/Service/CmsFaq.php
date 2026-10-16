<?php

/**
 * Portaliq CMS FAQ Reader (public-faq-and-product-finder)
 *
 * Reads the published FAQ entries and product finders of a portal.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The FAQ entries and the product finder of a portal.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */
class CmsFaq {
	/**
	 * Constructor.
	 *
	 * @param CmsContentCache $cache  The audience-keyed content cache.
	 * @param CmsRows         $rows   Reads the CMS rows.
	 * @param CmsReader       $reader Reads the pages a finder's products point at.
	 */
	public function __construct(
		private readonly CmsContentCache $cache,
		private readonly CmsRows $rows,
		private readonly CmsReader $reader,
	) {
	}//end __construct()

	/**
	 * Read the published FAQ entries of a portal, for one page or one topic or all.
	 *
	 * A draft entry is never served. The entries stand in their order.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 * @param string $page     Only the entries shown on this page route, or '' for no filter.
	 * @param string $topic    Only the entries of this topic, or '' for no filter.
	 *
	 * @return array The entries: question, answer, topic, pages.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	public function faq(string $portal, string $locale, string $audience, string $page='', string $topic=''): array {
		$key = $this->cache->key(portal: $portal, kind: 'faq', selector: '', locale: $locale, audience: $audience);
		$entries = $this->entries(key: $key, portal: $portal);

		return array_values(
			array_filter(
				$entries,
				static function (array $entry) use ($page, $topic): bool {
					if ($page !== '' && in_array($page, $entry['pages'], true) === false) {
						return false;
					}

					return ($topic === '' || $entry['topic'] === $topic);
				}
			)
		);
	}//end faq()

	/**
	 * The shaped entries of a portal: from the cache, else read and cached.
	 *
	 * @param string $key    The cache key.
	 * @param string $portal The portal slug.
	 *
	 * @return array The entries.
	 */
	private function entries(string $key, string $portal): array {
		$hit = $this->cache->lookup(key: $key);
		if ($hit !== null) {
			return (json_decode($hit, true) ?? []);
		}

		$entries = $this->shapeFaq(rows: $this->rows->query(schema: 'portalFaq', filters: ['portal' => $portal, 'status' => 'published']));
		$this->cache->store($key, json_encode($entries));

		return $entries;
	}//end entries()

	/**
	 * Shape the stored FAQ rows: published only, ordered.
	 *
	 * @param array $rows The stored rows.
	 *
	 * @return array The entries.
	 */
	private function shapeFaq(array $rows): array {
		$entries = [];
		foreach ($rows as $row) {
			$question = trim((string)($row['question'] ?? ''));
			if (($row['status'] ?? '') !== 'published' || $question === '') {
				continue;
			}

			$entries[] = [
				'question' => $question,
				'answer'   => (string)($row['answer'] ?? ''),
				'topic'    => (string)($row['topic'] ?? ''),
				'pages'    => array_values(array_filter(array_map('strval', (array)($row['pages'] ?? [])))),
				'order'    => (int)($row['order'] ?? 0),
			];
		}

		usort($entries, static fn ($a, $b) => [$a['order'], $a['question']] <=> [$b['order'], $b['question']]);

		return $entries;
	}//end shapeFaq()

	/**
	 * Read one published product finder of a portal.
	 *
	 * The products are named by route; each carries the title of its published
	 * page, and a route with no published page is left out, so the resident is
	 * never offered a product she cannot open.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $id       The finder's id, or '' for the first published one.
	 * @param string $locale   The locale.
	 * @param string $audience The requesting audience.
	 *
	 * @return array|null The finder, or null when there is none published.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	public function finder(string $portal, string $id, string $locale, string $audience): ?array {
		$titles = [];
		foreach ($this->reader->pages(portal: $portal, locale: $locale, audience: $audience) as $summary) {
			$titles[$summary['route']] = $summary['title'];
		}

		foreach ($this->rows->query(schema: 'portalFinder', filters: ['portal' => $portal, 'status' => 'published']) as $row) {
			$rowId = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
			if (($row['status'] ?? '') !== 'published' || ($id !== '' && $rowId !== $id)) {
				continue;
			}

			$products = [];
			foreach ((array)($row['products'] ?? []) as $route) {
				if (is_string($route) === true && isset($titles[$route]) === true) {
					$products[] = ['route' => $route, 'title' => $titles[$route]];
				}
			}

			return [
				'id'        => $rowId,
				'title'     => (string)($row['title'] ?? ''),
				'intro'     => (string)($row['intro'] ?? ''),
				'products'  => $products,
				'questions' => $this->shapeQuestions(questions: (array)($row['questions'] ?? [])),
			];
		}

		return null;
	}//end finder()

	/**
	 * The finder's questions with only the fields the browser needs.
	 *
	 * @param array $questions The stored questions.
	 *
	 * @return array The questions.
	 */
	private function shapeQuestions(array $questions): array {
		$shaped = [];
		foreach ($questions as $question) {
			if (is_array($question) === false || trim((string)($question['text'] ?? '')) === '') {
				continue;
			}

			$shaped[] = [
				'id'            => (string)($question['id'] ?? ''),
				'text'          => (string)$question['text'],
				'help'          => (string)($question['help'] ?? ''),
				'excludesOnYes' => array_values(array_filter(array_map('strval', (array)($question['excludesOnYes'] ?? [])))),
				'excludesOnNo'  => array_values(array_filter(array_map('strval', (array)($question['excludesOnNo'] ?? [])))),
			];
		}

		return $shaped;
	}//end shapeQuestions()
}//end class
