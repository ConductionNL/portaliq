<?php

/**
 * A server-side markdown converter for the plain (no JavaScript) site page.
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Renders a fixed markdown subset to HTML and escapes everything else.
 *
 * The subset: ATX headings, paragraphs, ordered and unordered lists, links,
 * emphasis (`*`, `**`, `_`, `__`), inline code and fenced code. Every other
 * character is escaped, so raw HTML in the source shows as text. A link keeps
 * its target only when it is `http`, `https`, `mailto` or a relative path;
 * any other link is rendered as its text alone.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainMarkdown {

	/**
	 * The schemes a link may point at; any other scheme loses its target.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMES = ['http', 'https', 'mailto'];

	/**
	 * Marks a finished inline piece while the rest of the line is escaped.
	 * NUL never survives into the output: it is stripped from the source first.
	 *
	 * @var string
	 */
	private const MARK = "\0";

	/**
	 * Render markdown to HTML.
	 *
	 * @param string $markdown The source.
	 *
	 * @return string The HTML; '' for an empty source.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.3
	 */
	public function render(string $markdown): string {
		$lines = explode("\n", str_replace(["\r\n", "\r", self::MARK], ["\n", "\n", ''], $markdown));
		$html  = [];
		$count = count($lines);
		$index = 0;
		while ($index < $count) {
			$index = $this->block(lines: $lines, index: $index, html: $html);
		}

		return implode("\n", $html);
	}//end render()

	/**
	 * Render the block that starts at a line, and answer the line after it.
	 *
	 * @param array<int, string> $lines The source lines.
	 * @param int                $index The line the block starts at.
	 * @param array<int, string> $html  The rendered blocks (appended to).
	 *
	 * @return int The index of the first line after the block.
	 */
	private function block(array $lines, int $index, array &$html): int {
		$line = $lines[$index];
		if (trim($line) === '') {
			return ($index + 1);
		}

		if (preg_match('/^\s{0,3}(```|~~~)/', $line, $fence) === 1) {
			return $this->fencedCode(lines: $lines, index: $index, fence: $fence[1], html: $html);
		}

		if (preg_match('/^\s{0,3}(#{1,6})[ \t]+(.*?)[ \t#]*$/', $line, $heading) === 1) {
			$level  = strlen($heading[1]);
			$html[] = '<h'.$level.'>'.$this->inline(text: $heading[2]).'</h'.$level.'>';
			return ($index + 1);
		}

		foreach (['ul' => '/^\s{0,3}[-*+][ \t]+(.*)$/', 'ol' => '/^\s{0,3}\d{1,9}[.)][ \t]+(.*)$/'] as $tag => $item) {
			if (preg_match($item, $line) === 1) {
				return $this->listBlock(lines: $lines, index: $index, list: ['tag' => $tag, 'item' => $item], html: $html);
			}
		}

		return $this->paragraph(lines: $lines, index: $index, html: $html);
	}//end block()

	/**
	 * A fenced code block: its lines escaped, up to the closing fence or the end.
	 *
	 * @param array<int, string> $lines The source lines.
	 * @param int                $index The opening fence's line.
	 * @param string             $fence The fence characters.
	 * @param array<int, string> $html  The rendered blocks (appended to).
	 *
	 * @return int The index after the closing fence.
	 */
	private function fencedCode(array $lines, int $index, string $fence, array &$html): int {
		$code  = [];
		$count = count($lines);
		$index++;
		while ($index < $count && preg_match('/^\s{0,3}'.preg_quote($fence, '/').'\s*$/', $lines[$index]) !== 1) {
			$code[] = $this->escape(text: $lines[$index]);
			$index++;
		}

		$html[] = '<pre><code>'.implode("\n", $code).'</code></pre>';
		return ($index + 1);
	}//end fencedCode()

	/**
	 * A list: consecutive items of one kind.
	 *
	 * @param array<int, string>                $lines The source lines.
	 * @param int                               $index The first item's line.
	 * @param array{tag: string, item: string}  $list  The list tag and its item pattern.
	 * @param array<int, string>                $html  The rendered blocks (appended to).
	 *
	 * @return int The index after the last item.
	 */
	private function listBlock(array $lines, int $index, array $list, array &$html): int {
		$items = [];
		$count = count($lines);
		while ($index < $count && preg_match($list['item'], $lines[$index], $item) === 1) {
			$items[] = '<li>'.$this->inline(text: trim($item[1])).'</li>';
			$index++;
		}

		$html[] = '<'.$list['tag'].'>'.implode('', $items).'</'.$list['tag'].'>';
		return $index;
	}//end listBlock()

	/**
	 * A paragraph: the lines up to a blank line or the start of another block.
	 *
	 * @param array<int, string> $lines The source lines.
	 * @param int                $index The first line.
	 * @param array<int, string> $html  The rendered blocks (appended to).
	 *
	 * @return int The index after the paragraph.
	 */
	private function paragraph(array $lines, int $index, array &$html): int {
		$text  = [trim($lines[$index])];
		$count = count($lines);
		$index++;
		while ($index < $count && trim($lines[$index]) !== '' && $this->startsBlock(line: $lines[$index]) === false) {
			$text[] = trim($lines[$index]);
			$index++;
		}

		$html[] = '<p>'.$this->inline(text: implode("\n", $text)).'</p>';
		return $index;
	}//end paragraph()

	/**
	 * Whether a line starts a block other than a paragraph.
	 *
	 * @param string $line The line.
	 *
	 * @return bool True for a heading, a fence or a list item.
	 */
	private function startsBlock(string $line): bool {
		return preg_match('/^\s{0,3}(```|~~~|#{1,6}[ \t]|[-*+][ \t]|\d{1,9}[.)][ \t])/', $line) === 1;
	}//end startsBlock()

	/**
	 * Render the inline markup of a text: code spans and links first, then
	 * everything else escaped, then emphasis on the escaped text.
	 *
	 * @param string $text The text.
	 *
	 * @return string The HTML.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.3
	 */
	private function inline(string $text): string {
		$pieces = [];
		$keep   = static function (string $html) use (&$pieces): string {
			$pieces[] = $html;
			return self::MARK.(count($pieces) - 1).self::MARK;
		};

		$text = (string)preg_replace_callback(
			'/(`+)(.+?)\1/s',
			fn (array $match): string => $keep('<code>'.$this->escape(text: $match[2]).'</code>'),
			$text
		);
		$text = (string)preg_replace_callback(
			'/\[([^\[\]]+)\]\(\s*([^()\s]*)\s*\)/',
			fn (array $match): string => $keep($this->link(label: $match[1], target: $match[2])),
			$text
		);

		$html = $this->emphasis(html: $this->escape(text: $text));

		return (string)preg_replace_callback(
			'/'.self::MARK.'(\d+)'.self::MARK.'/',
			static fn (array $match): string => $pieces[(int)$match[1]],
			$html
		);
	}//end inline()

	/**
	 * A link with an allowed target, or its label alone.
	 *
	 * @param string $label  The link text (markdown).
	 * @param string $target The target as written.
	 *
	 * @return string The HTML.
	 */
	private function link(string $label, string $target): string {
		$text = $this->emphasis(html: $this->escape(text: $label));
		if ($this->allowedTarget(target: $target) === false) {
			return $text;
		}

		return '<a href="'.$this->escape(text: $target).'">'.$text.'</a>';
	}//end link()

	/**
	 * Whether a link target is `http`, `https`, `mailto` or a relative path.
	 *
	 * A target with control characters or whitespace is refused, and so is a
	 * protocol-relative `//host` address, which leaves the site like an
	 * absolute one.
	 *
	 * @param string $target The target.
	 *
	 * @return bool True when it may be linked.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.3
	 */
	public function allowedTarget(string $target): bool {
		if ($target === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $target) === 1 || str_starts_with($target, '//') === true) {
			return false;
		}

		if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $target, $scheme) === 1) {
			return in_array(strtolower($scheme[1]), self::SCHEMES, true);
		}

		// No scheme: a relative path, query or fragment. A colon before the
		// first slash would make a browser read a scheme, so it is refused.
		$beforePath = strcspn($target, '/?#');
		return str_contains(substr($target, 0, $beforePath), ':') === false;
	}//end allowedTarget()

	/**
	 * Strong and emphasis on text that is already escaped. Underscores count
	 * only at word edges, so `snake_case_names` stay as they are.
	 *
	 * @param string $html The escaped text.
	 *
	 * @return string The text with `<strong>` and `<em>`.
	 */
	private function emphasis(string $html): string {
		$html = (string)preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $html);
		$html = (string)preg_replace('/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/su', '<strong>$1</strong>', $html);
		$html = (string)preg_replace('/\*(?=\S)(.+?)(?<=\S)\*/s', '<em>$1</em>', $html);
		return (string)preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/su', '<em>$1</em>', $html);
	}//end emphasis()

	/**
	 * Escape text for HTML content and attribute values.
	 *
	 * @param string $text The text.
	 *
	 * @return string The escaped text.
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5), 'UTF-8');
	}//end escape()
}//end class
