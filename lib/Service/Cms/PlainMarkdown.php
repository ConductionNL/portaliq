<?php

/**
 * Portaliq plain markdown
 *
 * Markdown to HTML for the plain version of a site page, which the server
 * renders for a visitor without JavaScript.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * A fixed subset, and nothing else: headings, paragraphs, ordered and
 * unordered lists, links, emphasis, inline code and fenced code. Every other
 * character is escaped, so raw HTML in a page body is shown as text. A link
 * keeps its target only for `http`, `https`, `mailto` or a relative address;
 * any other target (`javascript:`, `data:`) leaves the link text alone.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainMarkdown {

	/**
	 * The schemes a link may keep.
	 */
	private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

	/**
	 * Markdown source as HTML.
	 *
	 * @param string $markdown The source.
	 *
	 * @return string Safe HTML.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	public function toHtml(string $markdown): string {
		$lines = preg_split('/\r\n|\r|\n/', str_replace("\0", '', $markdown));
		if ($lines === false) {
			return '';
		}

		$html      = [];
		$paragraph = [];
		$list      = null;
		$items     = [];
		$fence     = null;

		$flushParagraph = function () use (&$paragraph, &$html): void {
			if ($paragraph !== []) {
				$html[]    = '<p>'.$this->inline(text: implode(' ', $paragraph)).'</p>';
				$paragraph = [];
			}
		};
		$flushList      = function () use (&$list, &$items, &$html): void {
			if ($list !== null) {
				$html[] = '<'.$list.'>'.implode('', array_map(fn (string $item): string => '<li>'.$this->inline(text: $item).'</li>', $items)).'</'.$list.'>';
				$list   = null;
				$items  = [];
			}
		};

		foreach ($lines as $line) {
			if ($fence !== null) {
				if (preg_match('/^\s*```/', $line) === 1) {
					$html[] = '<pre><code>'.$this->escape(text: implode("\n", $fence)).'</code></pre>';
					$fence  = null;
					continue;
				}

				$fence[] = $line;
				continue;
			}

			if (preg_match('/^\s*```/', $line) === 1) {
				$flushParagraph();
				$flushList();
				$fence = [];
				continue;
			}

			if (trim($line) === '') {
				$flushParagraph();
				$flushList();
				continue;
			}

			if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $match) === 1) {
				$flushParagraph();
				$flushList();
				$level  = strlen($match[1]);
				$html[] = '<h'.$level.'>'.$this->inline(text: $match[2]).'</h'.$level.'>';
				continue;
			}

			$kind = null;
			if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $match) === 1) {
				$kind = 'ul';
			} else if (preg_match('/^\s*\d{1,9}[.)]\s+(.*)$/', $line, $match) === 1) {
				$kind = 'ol';
			}

			if ($kind !== null) {
				$flushParagraph();
				if ($list !== $kind) {
					$flushList();
					$list = $kind;
				}

				$items[] = $match[1];
				continue;
			}

			if ($list !== null) {
				$items[count($items) - 1] .= ' '.trim($line);
				continue;
			}

			$paragraph[] = trim($line);
		}//end foreach

		if ($fence !== null) {
			$html[] = '<pre><code>'.$this->escape(text: implode("\n", $fence)).'</code></pre>';
		}

		$flushParagraph();
		$flushList();

		return implode("\n", $html);
	}//end toHtml()

	/**
	 * Whether a link target may be kept: http, https, mailto, or a relative
	 * address (a path, a fragment or a query, never `//host`).
	 *
	 * @param string $href The target, as written.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	public function isSafeTarget(string $href): bool {
		// Browsers drop tabs and newlines inside a URL, so `java\tscript:` is
		// read as the scheme it spells: control characters go before the check.
		$href = (string)preg_replace('/[\x00-\x20\x7f]+/', '', $href);
		if ($href === '' || str_starts_with($href, '//') === true || str_starts_with($href, '\\') === true) {
			return false;
		}

		if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $href, $match) === 1) {
			return in_array(strtolower($match[1]), self::SAFE_SCHEMES, true);
		}

		return true;
	}//end isSafeTarget()

	/**
	 * One line of inline markdown as HTML: code spans, links, strong and
	 * emphasis; everything else escaped.
	 *
	 * @param string $text The raw text.
	 *
	 * @return string
	 */
	private function inline(string $text): string {
		$held = [];
		$hold = static function (string $html) use (&$held): string {
			$held[] = $html;
			return "\0".(count($held) - 1)."\0";
		};

		$text = (string)preg_replace_callback(
			'/`([^`]+)`/',
			fn (array $m): string => $hold('<code>'.$this->escape(text: $m[1]).'</code>'),
			$text
		);

		$text = (string)preg_replace_callback(
			'/\[([^\]]+)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/',
			function (array $m) use ($hold): string {
				$label = $this->emphasis(html: $this->escape(text: $m[1]));
				if ($this->isSafeTarget(href: $m[2]) === false) {
					return $hold($label);
				}

				return $hold('<a href="'.$this->escape(text: (string)preg_replace('/[\x00-\x20\x7f]+/', '', $m[2])).'">'.$label.'</a>');
			},
			$text
		);

		$html = $this->emphasis(html: $this->escape(text: $text));

		return (string)preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $held[(int)$m[1]], $html);
	}//end inline()

	/**
	 * Strong and emphasis on text that is already escaped.
	 *
	 * @param string $html Escaped text.
	 *
	 * @return string
	 */
	private function emphasis(string $html): string {
		$html = (string)preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $html);
		$html = (string)preg_replace('/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/', '<strong>$1</strong>', $html);
		$html = (string)preg_replace('/\*(?=\S)(.+?)(?<=\S)\*/', '<em>$1</em>', $html);

		return (string)preg_replace('/(?<![\w])_(?=\S)(.+?)(?<=\S)_(?![\w])/', '<em>$1</em>', $html);
	}//end emphasis()

	/**
	 * HTML-escape text.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}//end escape()
}//end class
