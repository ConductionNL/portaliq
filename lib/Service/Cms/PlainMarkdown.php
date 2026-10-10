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
	 * The HTML blocks built so far.
	 *
	 * @var list<string>
	 */
	private array $html = [];

	/**
	 * The lines of the open paragraph.
	 *
	 * @var list<string>
	 */
	private array $paragraph = [];

	/**
	 * The items of the open list.
	 *
	 * @var list<string>
	 */
	private array $items = [];

	/**
	 * The open list's tag, `ul` or `ol`, or null.
	 *
	 * @var string|null
	 */
	private ?string $list = null;

	/**
	 * The lines of the open fenced code block, or null.
	 *
	 * @var list<string>|null
	 */
	private ?array $fence = null;

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

		$this->html      = [];
		$this->paragraph = [];
		$this->items     = [];
		$this->list      = null;
		$this->fence     = null;

		foreach ($lines as $line) {
			$this->line(line: $line);
		}

		if ($this->fence !== null) {
			$this->html[] = '<pre><code>'.$this->escape(text: implode("\n", $this->fence)).'</code></pre>';
		}

		$this->flushBlocks();

		return implode("\n", $this->html);
	}//end toHtml()

	/**
	 * Read one source line into the blocks being built.
	 *
	 * @param string $line The line.
	 *
	 * @return void
	 */
	private function line(string $line): void {
		$isFence = (preg_match('/^\s*```/', $line) === 1);
		if ($this->fence !== null) {
			if ($isFence === true) {
				$this->html[] = '<pre><code>'.$this->escape(text: implode("\n", $this->fence)).'</code></pre>';
				$this->fence  = null;
				return;
			}

			$this->fence[] = $line;
			return;
		}

		if ($isFence === true || trim($line) === '') {
			$this->flushBlocks();
			if ($isFence === true) {
				$this->fence = [];
			}

			return;
		}

		if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $match) === 1) {
			$this->flushBlocks();
			$level        = strlen($match[1]);
			$this->html[] = '<h'.$level.'>'.$this->inline(text: $match[2]).'</h'.$level.'>';
			return;
		}

		$this->textLine(line: $line);
	}//end line()

	/**
	 * A line that is a list item, the continuation of one, or paragraph text.
	 *
	 * @param string $line The line.
	 *
	 * @return void
	 */
	private function textLine(string $line): void {
		$kind = null;
		if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $match) === 1) {
			$kind = 'ul';
		} else if (preg_match('/^\s*\d{1,9}[.)]\s+(.*)$/', $line, $match) === 1) {
			$kind = 'ol';
		}

		if ($kind !== null) {
			$this->flushParagraph();
			if ($this->list !== $kind) {
				$this->flushList();
				$this->list = $kind;
			}

			$this->items[] = $match[1];
			return;
		}

		if ($this->list !== null) {
			$this->items[count($this->items) - 1] .= ' '.trim($line);
			return;
		}

		$this->paragraph[] = trim($line);
	}//end textLine()

	/**
	 * Close the open paragraph and the open list.
	 *
	 * @return void
	 */
	private function flushBlocks(): void {
		$this->flushParagraph();
		$this->flushList();
	}//end flushBlocks()

	/**
	 * Close the open paragraph.
	 *
	 * @return void
	 */
	private function flushParagraph(): void {
		if ($this->paragraph !== []) {
			$this->html[]    = '<p>'.$this->inline(text: implode(' ', $this->paragraph)).'</p>';
			$this->paragraph = [];
		}
	}//end flushParagraph()

	/**
	 * Close the open list.
	 *
	 * @return void
	 */
	private function flushList(): void {
		if ($this->list === null) {
			return;
		}

		$items = '';
		foreach ($this->items as $item) {
			$items .= '<li>'.$this->inline(text: $item).'</li>';
		}

		$this->html[] = '<'.$this->list.'>'.$items.'</'.$this->list.'>';
		$this->list   = null;
		$this->items  = [];
	}//end flushList()

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
			fn (array $match): string => $hold('<code>'.$this->escape(text: $match[1]).'</code>'),
			$text
		);

		$text = (string)preg_replace_callback(
			'/\[([^\]]+)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/',
			function (array $match) use ($hold): string {
				$label = $this->emphasis(html: $this->escape(text: $match[1]));
				if ($this->isSafeTarget(href: $match[2]) === false) {
					return $hold($label);
				}

				return $hold('<a href="'.$this->escape(text: (string)preg_replace('/[\x00-\x20\x7f]+/', '', $match[2])).'">'.$label.'</a>');
			},
			$text
		);

		$html = $this->emphasis(html: $this->escape(text: $text));

		return (string)preg_replace_callback('/\x00(\d+)\x00/', static fn (array $match): string => $held[(int)$match[1]], $html);
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
