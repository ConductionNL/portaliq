<?php

/**
 * Portaliq Public Detail Shape (public-detail-page-for-a-provider-item)
 *
 * An app MAY give an item of its public index a page of its own through an
 * optional provider method `getPublicDetail(string $portal, string $id): ?array`
 * where `$id` is the item's id in the app's own index. The answer is held to
 * one shape and what does not fit is dropped, so nothing an app returns reaches
 * a visitor as it came:
 *
 *     {title?, facts?[{label, value}], sections?[{heading, markdown}],
 *      dates?[{id, date, endDate?, label?, places?, placesLine?}],
 *      documents?[{label, href}], secondaryLink?{label, href},
 *      action?{id, label, requiresSignIn, countLabel?, countMax?}}
 *
 * `href` is a path inside the site or an http(s) address, nothing else.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Holds a public detail answer to its fixed shape.
 *
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-1
 */
class PublicDetailShape {
	/**
	 * The contract method an app may implement.
	 */
	public const METHOD = 'getPublicDetail';

	private const MAX_ROWS = 50;

	private const MAX_SHORT = 200;

	private const MAX_MARKDOWN = 8000;

	/**
	 * Keep what fits; null when the answer is not an array.
	 *
	 * @param mixed $answer What the app returned.
	 *
	 * @return array<string, mixed>|null The detail, possibly empty of sections.
	 *
	 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md#task-1
	 */
	public function detail(mixed $answer): ?array {
		if (is_array($answer) === false) {
			return null;
		}

		$out   = [];
		$title = $this->short(value: ($answer['title'] ?? null));
		if ($title !== null) {
			$out['title'] = $title;
		}

		$out['facts']     = $this->facts(declared: ($answer['facts'] ?? null));
		$out['sections']  = $this->sections(declared: ($answer['sections'] ?? null));
		$out['dates']     = $this->dates(declared: ($answer['dates'] ?? null));
		$out['documents'] = $this->links(declared: ($answer['documents'] ?? null));

		$secondary = $this->links(declared: [($answer['secondaryLink'] ?? null)]);
		if ($secondary !== []) {
			$out['secondaryLink'] = $secondary[0];
		}

		$action = $this->action(declared: ($answer['action'] ?? null));
		if ($action !== null) {
			$out['action'] = $action;
		}

		return $out;
	}//end detail()

	/**
	 * @param mixed $declared A list of `{label, value}`.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function facts(mixed $declared): array {
		$out = [];
		foreach ($this->rows(declared: $declared) as $row) {
			$label = $this->short(value: ($row['label'] ?? null));
			$value = $this->short(value: ($row['value'] ?? null));
			if ($label !== null && $value !== null) {
				$out[] = ['label' => $label, 'value' => $value];
			}
		}

		return $out;
	}//end facts()

	/**
	 * @param mixed $declared A list of `{heading, markdown}`.
	 *
	 * @return array<int, array{heading: string, markdown: string}>
	 */
	private function sections(mixed $declared): array {
		$out = [];
		foreach ($this->rows(declared: $declared) as $row) {
			$heading  = $this->short(value: ($row['heading'] ?? null));
			$markdown = $row['markdown'] ?? null;
			if ($heading === null || is_string($markdown) === false || trim($markdown) === '') {
				continue;
			}

			$out[] = ['heading' => $heading, 'markdown' => mb_substr(trim($markdown), 0, self::MAX_MARKDOWN)];
		}

		return $out;
	}//end sections()

	/**
	 * @param mixed $declared A list of dated choices.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dates(mixed $declared): array {
		$out = [];
		foreach ($this->rows(declared: $declared) as $row) {
			$id   = $this->short(value: ($row['id'] ?? null));
			$date = $this->day(value: ($row['date'] ?? null));
			if ($id === null || $date === null) {
				continue;
			}

			$item = ['id' => $id, 'date' => $date];
			$end  = $this->day(value: ($row['endDate'] ?? null));
			if ($end !== null) {
				$item['endDate'] = $end;
			}

			foreach (['label', 'placesLine'] as $key) {
				$text = $this->short(value: ($row[$key] ?? null));
				if ($text !== null) {
					$item[$key] = $text;
				}
			}

			if (is_int($row['places'] ?? null) === true && $row['places'] >= 0) {
				$item['places'] = $row['places'];
			}

			$out[] = $item;
		}//end foreach

		return $out;
	}//end dates()

	/**
	 * @param mixed $declared A list of `{label, href}`.
	 *
	 * @return array<int, array{label: string, href: string}>
	 */
	private function links(mixed $declared): array {
		$out = [];
		foreach ($this->rows(declared: $declared) as $row) {
			$label = $this->short(value: ($row['label'] ?? null));
			$href  = $this->short(value: ($row['href'] ?? null));
			if ($label === null || $href === null) {
				continue;
			}

			if (preg_match('#^/(?!/)[^\s\\\\]*$#', $href) === 1 || preg_match('#^https?://[^\s/]+[^\s]*$#i', $href) === 1) {
				$out[] = ['label' => $label, 'href' => $href];
			}
		}

		return $out;
	}//end links()

	/**
	 * @param mixed $declared The action the page places.
	 *
	 * @return array<string, mixed>|null
	 */
	private function action(mixed $declared): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$id    = $this->short(value: ($declared['id'] ?? null));
		$label = $this->short(value: ($declared['label'] ?? null));
		if ($id === null || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $id) !== 1 || $label === null) {
			return null;
		}

		// Sign-in is required unless the app says in so many words that it is not:
		// an action that asks for nothing is the unsafe state.
		$out   = ['id' => $id, 'label' => $label, 'requiresSignIn' => (($declared['requiresSignIn'] ?? true) !== false)];
		$count = $this->short(value: ($declared['countLabel'] ?? null));
		if ($count !== null) {
			$max = ($declared['countMax'] ?? 20);
			if (is_int($max) === false || $max < 1 || $max > 100) {
				$max = 20;
			}

			$out['countLabel'] = $count;
			$out['countMax']   = $max;
		}

		return $out;
	}//end action()

	/**
	 * @param mixed $declared A list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter(array_slice(array_values($declared), 0, self::MAX_ROWS), 'is_array'));
	}//end rows()

	/**
	 * @param mixed $value A day, `YYYY-MM-DD`.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false) {
			return $value;
		}

		return null;
	}//end day()

	/**
	 * @param mixed $value Any value.
	 *
	 * @return string|null Plain one-line text, or null.
	 */
	private function short(mixed $value): ?string {
		if (is_int($value) === true) {
			$value = (string)$value;
		}

		if (is_string($value) === false) {
			return null;
		}

		$value = trim(strip_tags($value));
		if ($value === '') {
			return null;
		}

		return mb_substr((string)preg_replace('/\s+/', ' ', $value), 0, self::MAX_SHORT);
	}//end short()
}//end class
