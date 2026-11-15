<?php

/**
 * The text widgets of the plain site page.
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The text of the widgets that are only text: markdown, heading, paragraph,
 * list and link list, read with the props their components take
 * (`src/site/widgets/<key>/`). Each answers null when the widget holds no
 * text, and the page then says the widget needs JavaScript instead.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainTextBlocks {

	/**
	 * Constructor.
	 *
	 * @param PlainMarkdown $markdown The markdown subset.
	 */
	public function __construct(
		private readonly PlainMarkdown $markdown,
	) {
	}//end __construct()

	/**
	 * A markdown widget, or null when it holds no text.
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function markdownBlock(array $props): ?array {
		$html = $this->markdown->render(markdown: $this->text(value: $props['markdown'] ?? null));
		if ($html === '') {
			return null;
		}

		return ['kind' => 'html', 'html' => $html];
	}//end markdownBlock()

	/**
	 * A heading widget: its text at its level, held to 1 to 6 (2 by default).
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function headingBlock(array $props): ?array {
		$text = $this->text(value: $props['text'] ?? null);
		if ($text === '') {
			return null;
		}

		$level = 2;
		if (is_numeric($props['level'] ?? null) === true) {
			$level = max(1, min(6, (int)$props['level']));
		}

		return ['kind' => 'heading', 'level' => $level, 'text' => $text];
	}//end headingBlock()

	/**
	 * A paragraph widget.
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function paragraphBlock(array $props): ?array {
		$text = $this->text(value: $props['text'] ?? null);
		if ($text === '') {
			return null;
		}

		return ['kind' => 'paragraph', 'text' => $text, 'lead' => (($props['lead'] ?? false) === true)];
	}//end paragraphBlock()

	/**
	 * A list widget: its lines (a string, or `{title, text}`), as
	 * `src/site/widgets/nlList/lines.js` reads them.
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function listBlock(array $props): ?array {
		$items = [];
		foreach ($this->listOf(value: $props['items'] ?? null) as $item) {
			$line = ['title' => $this->text(value: $item), 'text' => ''];
			if (is_array($item) === true) {
				$line = ['title' => $this->text(value: $item['title'] ?? null), 'text' => $this->text(value: $item['text'] ?? null)];
			}

			if ($line['title'] !== '') {
				$items[] = $line;
			}
		}

		if ($items === []) {
			return null;
		}

		return ['kind' => 'list', 'ordered' => (($props['ordered'] ?? false) === true), 'items' => $items];
	}//end listBlock()

	/**
	 * A link list widget: its heading, intro and the links that have an
	 * address this page may link, as `NlLinkList.vue` keeps them.
	 *
	 * @param array<string, mixed> $props   The props.
	 * @param PlainAddress         $address The addresses.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function linksBlock(array $props, PlainAddress $address): ?array {
		$links = [];
		foreach ($this->listOf(value: $props['links'] ?? null) as $link) {
			if (is_array($link) === false) {
				continue;
			}

			$raw = $this->text(value: $link['href'] ?? null);
			$href = $address->authored(link: $raw);
			if ($href === '') {
				continue;
			}

			$label = $this->text(value: $link['label'] ?? null);
			if ($label === '') {
				$label = $raw;
			}

			$links[] = [
				'label' => $label,
				'href' => $href,
				'description' => $this->text(value: $link['description'] ?? null),
			];
		}

		if ($links === []) {
			return null;
		}

		return [
			'kind' => 'links',
			'heading' => $this->text(value: $props['heading'] ?? null),
			'intro' => $this->text(value: $props['intro'] ?? null),
			'links' => $links,
		];
	}//end linksBlock()

	/**
	 * A prop as an array, or [] for anything that is not one.
	 *
	 * @param mixed $value The prop.
	 *
	 * @return array<mixed>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end listOf()

	/**
	 * A prop as a trimmed string; anything that is not a string or a number is ''.
	 *
	 * @param mixed $value The prop.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true || is_int($value) === true || is_float($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end text()
}//end class
