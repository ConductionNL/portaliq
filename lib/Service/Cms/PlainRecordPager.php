<?php

/**
 * The pages of a public record's rows on the plain page.
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

use OCP\IL10N;

/**
 * One page of a record's rows, the page's count line, and previous and next
 * as plain links that keep the record (board PtStemgedrag: "1 tot 5 van 48").
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/specs/site-council-records/spec.md#requirement-a-visitor-must-read-one-members-voting-record-without-signing-in-req-scr-006
 */
class PlainRecordPager {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The words.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * One page of rows and where it sits; `from` and `to` count from 1, and a
	 * page past the end shows the last one. Size 0 shows every row.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @param int                        $page The page asked for, from 1.
	 * @param int                        $size Rows per page, 0 for all.
	 *
	 * @return array{rows: list<array<string, mixed>>, page: int, pages: int, from: int, to: int, total: int}
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function page(array $rows, int $page, int $size): array {
		$total = count($rows);
		$per = $size;
		if ($per < 1) {
			$per = max($total, 1);
		}

		$pages = max(1, (int)ceil($total / $per));
		$current = min(max(1, $page), $pages);
		$start = ($current - 1) * $per;
		$shown = array_slice($rows, $start, $per);
		$from = 0;
		if ($shown !== []) {
			$from = $start + 1;
		}

		return ['rows' => $shown, 'page' => $current, 'pages' => $pages, 'from' => $from, 'to' => $start + count($shown), 'total' => $total];
	}//end page()

	/**
	 * The page's count line with `{count}`, `{from}` and `{to}` filled; none
	 * when the page writes none.
	 *
	 * @param string                    $text  The page's sentence.
	 * @param array<string, int|mixed> $paged The page of rows.
	 *
	 * @return string
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function countText(string $text, array $paged): string {
		if ($text === '') {
			return '';
		}

		return strtr($text, ['{count}' => (string)$paged['total'], '{from}' => (string)$paged['from'], '{to}' => (string)$paged['to']]);
	}//end countText()

	/**
	 * Previous and next as plain links, keeping the record; none on one page.
	 *
	 * @param array<string, int|mixed> $paged   The page of rows.
	 * @param PlainContext             $context The request.
	 *
	 * @return array<string, string>
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function links(array $paged, PlainContext $context): array {
		$page = (int)$paged['page'];
		$pages = (int)$paged['pages'];
		$previous = '';
		if ($page > 1) {
			$previous = $context->address->plain(route: $context->route, extra: $this->params(record: $context->record, page: $page - 1));
		}

		$next = '';
		if ($page < $pages) {
			$next = $context->address->plain(route: $context->route, extra: $this->params(record: $context->record, page: $page + 1));
		}

		$rangeText = '';
		if ($pages > 1) {
			$rangeText = $this->l10n->t('%1$d to %2$d of %3$d', [$paged['from'], $paged['to'], $paged['total']]);
		}

		return [
			'previous' => $previous,
			'next' => $next,
			'previousLabel' => $this->l10n->t('Previous page'),
			'nextLabel' => $this->l10n->t('Next page'),
			'rangeText' => $rangeText,
		];
	}//end links()

	/**
	 * The record and page of a pager link; page 1 left out.
	 *
	 * @param string $record The record id.
	 * @param int    $page   The page.
	 *
	 * @return array<string, string>
	 */
	private function params(string $record, int $page): array {
		$params = ['record' => $record];
		if ($page > 1) {
			$params['_page'] = (string)$page;
		}

		return $params;
	}//end params()
}//end class
