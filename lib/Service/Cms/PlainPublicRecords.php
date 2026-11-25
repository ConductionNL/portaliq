<?php

/**
 * The plain public records block: a contributed record list, or one record, without JavaScript.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PublicRecordReaderInterface;
use OCP\IL10N;

/**
 * The view model of a `publicRecords` widget on the plain page.
 *
 * Without a record id it is the list: a GET search form over the entries'
 * title and subtitle (`_search`), and one link per entry to the same plain
 * page with `record=<id>`. With a record id it is that record: the heading
 * (inside the page's sentence when it writes one), the subtitle, the summary
 * figures, the rows as a table in the provider's column order with the
 * subject linked when the row carries one, a pager when the page sets a page
 * size, the provider's note, the link back to the list and the page's links.
 *
 * Every read goes through `PublicRecordReader`, which holds the list to 500
 * entries and fetches a record only for an id the list holds; an id the list
 * does not hold is `gone`, and the page answers 404 (REQ-SCR-006).
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/specs/site-council-records/spec.md#requirement-a-visitor-must-read-one-members-voting-record-without-signing-in-req-scr-006
 */
class PlainPublicRecords {

	/**
	 * The most rows one page of a record shows.
	 *
	 * @var int
	 */
	private const MAX_PAGE_SIZE = 100;

	/**
	 * Pages a record's rows.
	 *
	 * @var PlainRecordPager
	 */
	private readonly PlainRecordPager $pager;

	/**
	 * Constructor.
	 *
	 * @param PublicRecordReaderInterface $reader The public records read.
	 * @param IL10N                       $l10n   The words.
	 */
	public function __construct(
		private readonly PublicRecordReaderInterface $reader,
		private readonly IL10N $l10n,
	) {
		$this->pager = new PlainRecordPager(l10n: $l10n);
	}//end __construct()

	/**
	 * The block of one widget, or null when it names no app or no list.
	 *
	 * @param array<string, mixed> $props   The widget's props.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function build(array $props, PlainContext $context): ?array {
		$app = $this->string(value: $props['app'] ?? null);
		$list = $this->string(value: $props['list'] ?? null);
		if ($app === '' || $list === '') {
			return null;
		}

		if ($context->record !== '') {
			return $this->record(app: $app, list: $list, props: $props, context: $context);
		}

		return $this->list(app: $app, list: $list, props: $props, context: $context);
	}//end build()

	/**
	 * The list: the search form and the matching entries as links.
	 *
	 * @param string               $app     The contributing app.
	 * @param string               $list    The list id.
	 * @param array<string, mixed> $props   The widget's props.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>
	 */
	private function list(string $app, string $list, array $props, PlainContext $context): array {
		$block = [
			'kind' => 'records',
			'mode' => 'list',
			'title' => $this->string(value: $props['title'] ?? null),
			'fullUrl' => $context->fullUrl(),
			'fullLabel' => $this->l10n->t('Open the full page'),
		];

		$entries = $this->reader->entries(app: $app, list: $list);
		if ($entries === null) {
			return $block + $this->gone();
		}

		$label = $this->string(value: $props['searchLabel'] ?? null);
		if ($label === '') {
			$label = $this->l10n->t('Search for a name');
		}

		$links = [];
		foreach ($this->matching(entries: $entries, query: $context->query) as $entry) {
			$links[] = [
				'label' => $entry['title'],
				'href' => $context->address->plain(route: $context->route, extra: ['record' => $entry['id']]),
				'description' => (string)($entry['subtitle'] ?? ''),
			];
		}

		$empty = '';
		if ($links === []) {
			$empty = $this->l10n->t('Nothing matches this search.');
		}

		return $block + [
			'state' => 'ok',
			'action' => $context->address->plainBase(),
			'route' => '/' . trim($context->route, '/'),
			'portal' => $context->address->portalSlug(),
			'label' => $label,
			'submit' => $this->l10n->t('Search'),
			'query' => $context->query,
			'links' => $links,
			'empty' => $empty,
		];
	}//end list()

	/**
	 * One record, or `gone` when the list does not hold its id.
	 *
	 * @param string               $app     The contributing app.
	 * @param string               $list    The list id.
	 * @param array<string, mixed> $props   The widget's props.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $app, string $list, array $props, PlainContext $context): array {
		$backLabel = $this->string(value: $props['backText'] ?? null);
		if ($backLabel === '') {
			$backLabel = $this->l10n->t('Back to the overview');
		}

		$block = [
			'kind' => 'records',
			'mode' => 'record',
			'backHref' => $context->address->plain(route: $context->route),
			'backLabel' => $backLabel,
			'fullUrl' => $context->fullUrl(),
			'fullLabel' => $this->l10n->t('Open the full page'),
		];

		$record = $this->reader->record(app: $app, list: $list, id: $context->record);
		if ($record === null) {
			return $block + $this->gone();
		}

		$title = (string)($record['title'] ?? '');
		$heading = str_replace('{title}', $title, $this->string(value: $props['recordHeading'] ?? null));
		if ($heading === '') {
			$heading = $title;
		}

		$columns = array_values(array_filter((array)($record['columns'] ?? []), 'is_array'));
		$rows = array_values(array_filter((array)($record['rows'] ?? []), 'is_array'));
		$paged = $this->pager->page(rows: $rows, page: $context->page, size: $this->pageSize(value: $props['pageSize'] ?? null));
		$linkKey = $this->string(value: $props['linkKey'] ?? null);
		if ($linkKey === '') {
			$linkKey = 'subject';
		}

		return $block + [
			'state' => 'ok',
			'heading' => $heading,
			'title' => $title,
			'subtitle' => (string)($record['subtitle'] ?? ''),
			'figures' => $this->figures(summary: (array)($record['summary'] ?? [])),
			'columns' => array_map(static fn (array $column): string => (string)($column['label'] ?? ''), $columns),
			'rows' => $this->cells(rows: $paged['rows'], columns: $columns, linkKey: $linkKey, address: $context->address),
			'countText' => $this->pager->countText(text: $this->string(value: $props['countText'] ?? null), paged: $paged),
			'note' => (string)($record['note'] ?? ''),
			'links' => $this->links(links: $props['links'] ?? null, address: $context->address),
		] + $this->pager->links(paged: $paged, context: $context);
	}//end record()

	/**
	 * What a list or record that is no longer there shows.
	 *
	 * @return array<string, mixed>
	 */
	private function gone(): array {
		return [
			'state' => 'gone',
			'sentence' => $this->l10n->t('This overview no longer exists.'),
		];
	}//end gone()

	/**
	 * The entries whose title or subtitle holds the search term; all of them
	 * without one.
	 *
	 * @param array<int, array<string, string>> $entries The list.
	 * @param string                            $query   The term.
	 *
	 * @return list<array<string, string>>
	 */
	private function matching(array $entries, string $query): array {
		$needle = mb_strtolower(trim($query));
		$kept = [];
		foreach ($entries as $entry) {
			$id = (string)($entry['id'] ?? '');
			$title = (string)($entry['title'] ?? '');
			if ($id === '' || $title === '') {
				continue;
			}

			$haystack = mb_strtolower($title . ' ' . (string)($entry['subtitle'] ?? ''));
			if ($needle === '' || str_contains($haystack, $needle) === true) {
				$kept[] = $entry;
			}
		}

		return $kept;
	}//end matching()

	/**
	 * The summary as label, value and detail; a card without a label is left out.
	 *
	 * @param array<int|string, mixed> $summary The record's summary.
	 *
	 * @return list<array{label: string, value: string, detail: string}>
	 */
	private function figures(array $summary): array {
		$figures = [];
		foreach ($summary as $card) {
			if (is_array($card) === false || (string)($card['label'] ?? '') === '') {
				continue;
			}

			$figures[] = [
				'label' => (string)$card['label'],
				'value' => (string)($card['value'] ?? ''),
				'detail' => (string)($card['detail'] ?? ''),
			];
		}

		return $figures;
	}//end figures()

	/**
	 * The rows as cells in column order; the link column links to the row's
	 * subject when it has one (a site path through the plain page, an http(s)
	 * address as it is).
	 *
	 * @param list<array<string, mixed>>   $rows    The rows of this page.
	 * @param list<array<string, mixed>>   $columns The record's columns.
	 * @param string                       $linkKey The column that links.
	 * @param PlainAddress                 $address The addresses.
	 *
	 * @return list<list<array{text: string, href: string}>>
	 */
	private function cells(array $rows, array $columns, string $linkKey, PlainAddress $address): array {
		$table = [];
		foreach ($rows as $row) {
			$cells = [];
			foreach ($columns as $column) {
				$key = (string)($column['key'] ?? '');
				$href = '';
				if ($key === $linkKey) {
					$href = $address->authored(link: (string)($row['subjectUrl'] ?? ''));
				}

				$cells[] = ['text' => (string)($row[$key] ?? ''), 'href' => $href];
			}

			$table[] = $cells;
		}

		return $table;
	}//end cells()

	/**
	 * The page's links under the record: a label and a site route or an
	 * http(s) address; anything else is left out.
	 *
	 * @param mixed        $links   The prop.
	 * @param PlainAddress $address The addresses.
	 *
	 * @return list<array{label: string, href: string, description: string}>
	 */
	private function links(mixed $links, PlainAddress $address): array {
		$kept = [];
		foreach ((array)$links as $link) {
			if (is_array($link) === false) {
				continue;
			}

			$label = $this->string(value: $link['label'] ?? null);
			$href = $address->authored(link: $this->string(value: $link['href'] ?? null));
			if ($label === '' || $href === '' || str_starts_with($href, 'mailto:') === true || str_starts_with($href, 'tel:') === true) {
				continue;
			}

			$kept[] = ['label' => $label, 'href' => $href, 'description' => ''];
		}

		return $kept;
	}//end links()

	/**
	 * Rows per page: the widget's own, at most 100; 0 (the default) shows every row.
	 *
	 * @param mixed $value The prop.
	 *
	 * @return int
	 */
	private function pageSize(mixed $value): int {
		if (is_numeric($value) === false || (int)$value < 1) {
			return 0;
		}

		return min(self::MAX_PAGE_SIZE, (int)$value);
	}//end pageSize()

	/**
	 * A prop as a trimmed string, else ''.
	 *
	 * @param mixed $value The prop.
	 *
	 * @return string
	 */
	private function string(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		return '';
	}//end string()
}//end class
