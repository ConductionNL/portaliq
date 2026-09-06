<?php

/**
 * Portaliq Traffic Recording Mask.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The shape a recording chunk is allowed to have, enforced at the edge.
 *
 * THE RECORDER MASKS; THIS CLASS REFUSES TO STORE ANYTHING ELSE. The
 * browser half of session recording (portal-traffic-experiments) sends a
 * document as a tree in which every text node is only its length and
 * every input only the length of its value. That is a promise made by
 * code that runs on somebody else's machine, so it is not the promise
 * the store relies on. Every chunk is walked here: a text node keeps its
 * length and nothing else, an element keeps its tag, its children and
 * the attributes on one short list, and every other key, whatever it
 * carries, is dropped. A recorder that was tampered with, or an old one
 * with a bug, cannot land a name in the store through this door.
 *
 * Pure: a chunk in, a smaller chunk out.
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
 */
class TrafficRecordingMask {

	/**
	 * The attributes a stored element may carry. Layout and identity of
	 * the ELEMENT, never of the person: no `alt`, `title`, `placeholder`,
	 * `aria-*`, `data-*`, `value`, `src` or `href` (a stylesheet link is
	 * the one exception, under `rel`).
	 *
	 * @var string[]
	 */
	public const ATTRIBUTES = [
		'class',
		'id',
		'style',
		'type',
		'rel',
		'href',
		'width',
		'height',
		'role',
		'dir',
		'lang',
		'hidden',
		'disabled',
		'colspan',
		'rowspan',
		'size',
		'rows',
		'cols',
		'viewBox',
		'd',
		'fill',
		'stroke',
		'stroke-width',
		'x',
		'y',
		'cx',
		'cy',
		'r',
		'points',
		'transform',
		'xmlns',
	];

	/**
	 * The event kinds a chunk may carry and the numeric fields of each: a
	 * snapshot, a stylesheet (sent once, referenced by hash), a pointer
	 * move, a click, a scroll, a viewport size and a navigation.
	 *
	 * @var array<string, string[]>
	 */
	public const KINDS = [
		's' => ['t', 'w', 'h'],
		'y' => ['t'],
		'm' => ['t', 'x', 'y'],
		'c' => ['t', 'x', 'y'],
		'r' => ['t', 'x', 'y'],
		'v' => ['t', 'w', 'h'],
		'n' => ['t'],
	];

	/**
	 * The longest attribute value kept, the longest stylesheet text kept,
	 * and the deepest tree walked.
	 */
	private const MAX_ATTRIBUTE = 512;

	/**
	 * The longest stylesheet kept: a stylesheet travels ONCE per visit as
	 * its own event (`y`, with the hash a snapshot's style element refers
	 * to), so the bound is per sheet, not per snapshot.
	 */
	private const MAX_STYLE = 131072;

	/**
	 * The deepest element nesting walked.
	 */
	private const MAX_DEPTH = 64;

	/**
	 * The longest page path kept on a navigation.
	 */
	private const MAX_PATH = 256;

	/**
	 * The chunk's events, reduced to what may be stored.
	 *
	 * @param mixed $events The posted events.
	 *
	 * @return array<int, array<string, mixed>> The storable events.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
	 */
	public function events(mixed $events): array {
		if (is_array($events) === false) {
			return [];
		}

		$out = [];
		foreach ($events as $event) {
			$clean = $this->event(event: $event);
			if ($clean !== null) {
				$out[] = $clean;
			}
		}

		return $out;
	}

	/**
	 * One event, or null when its kind is unknown.
	 *
	 * @param mixed $event The posted event.
	 *
	 * @return array<string, mixed>|null The storable event.
	 */
	private function event(mixed $event): ?array {
		if (is_array($event) === false) {
			return null;
		}

		$kind = (string)($event['k'] ?? '');
		if (isset(self::KINDS[$kind]) === false) {
			return null;
		}

		$out = ['k' => $kind];
		foreach (self::KINDS[$kind] as $field) {
			$out[$field] = $this->number(value: ($event[$field] ?? 0));
		}

		if ($kind === 's') {
			$out['n'] = $this->node(node: ($event['n'] ?? null), depth: 0);
		}

		if ($kind === 'y') {
			$sheet = $event['s'] ?? '';
			if (is_string($sheet) === false) {
				$sheet = '';
			}

			$out['h'] = $this->hash(value: ($event['h'] ?? null));
			$out['s'] = $this->css(value: $sheet);
		}

		if ($kind === 'n') {
			$out['p'] = $this->path(value: ($event['p'] ?? null));
		}

		return $out;
	}

	/**
	 * One node of a snapshot: a text node keeps its length, an element
	 * its tag, allowed attributes, value length and children. Anything
	 * else becomes an empty text node.
	 *
	 * @param mixed $node  The posted node.
	 * @param int   $depth How deep the walk is.
	 *
	 * @return array<string, mixed> The storable node.
	 */
	private function node(mixed $node, int $depth): array {
		if (is_array($node) === false || $depth > self::MAX_DEPTH) {
			return ['l' => 0];
		}

		if (isset($node['n']) === false) {
			return ['l' => max(0, (int)($node['l'] ?? 0))];
		}

		$tag = strtolower((string)$node['n']);
		if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $tag) !== 1 || in_array($tag, ['script', 'noscript', 'template'], true) === true) {
			return ['l' => 0];
		}

		$out = $this->element(tag: $tag, node: $node);
		$children = [];
		foreach ((array)($node['c'] ?? []) as $child) {
			$children[] = $this->node(node: $child, depth: $depth + 1);
		}

		$out['c'] = $children;

		return $out;
	}

	/**
	 * An element's own fields: tag, allowed attributes, the length of its
	 * value, and for a style element its stylesheet text.
	 *
	 * @param string               $tag  The lower-cased tag.
	 * @param array<string, mixed> $node The posted node.
	 *
	 * @return array<string, mixed> The element without its children.
	 */
	private function element(string $tag, array $node): array {
		$out = ['n' => $tag, 'a' => $this->attributes(tag: $tag, attributes: ($node['a'] ?? null))];
		if (isset($node['v']) === true) {
			$out['v'] = max(0, (int)$node['v']);
		}

		if ($tag === 'style') {
			$out['h'] = $this->hash(value: ($node['h'] ?? null));
			if (is_string($node['s'] ?? null) === true) {
				$out['s'] = $this->css(value: $node['s']);
			}
		}

		return $out;
	}

	/**
	 * A stylesheet hash: up to sixteen hex characters, else ''.
	 *
	 * @param mixed $value The posted hash.
	 *
	 * @return string The hash.
	 */
	private function hash(mixed $value): string {
		if (is_string($value) === false || preg_match('/^[a-f0-9]{1,16}$/', $value) !== 1) {
			return '';
		}

		return $value;
	}

	/**
	 * The allowed attributes of an element, bounded. A `href` survives
	 * only on a stylesheet link, and a `style` loses every `url(`.
	 *
	 * @param string $tag        The element's tag.
	 * @param mixed  $attributes The posted attributes.
	 *
	 * @return array<string, string> The storable attributes.
	 */
	private function attributes(string $tag, mixed $attributes): array {
		if (is_array($attributes) === false) {
			return [];
		}

		$out = [];
		foreach (self::ATTRIBUTES as $name) {
			$value = $attributes[$name] ?? null;
			if (is_string($value) === false && is_numeric($value) === false) {
				continue;
			}

			$value = mb_substr((string)$value, 0, self::MAX_ATTRIBUTE);
			if ($name === 'href' && ($tag !== 'link' || preg_match('/^https?:\/\//i', $value) !== 1)) {
				continue;
			}

			if ($name === 'style') {
				$value = $this->css(value: $value);
			}

			$out[$name] = $value;
		}

		return $out;
	}

	/**
	 * Stylesheet text with every `url(` and every `@import` removed, so
	 * a replay cannot fetch anything the recorded page could.
	 *
	 * @param string $value The posted CSS.
	 *
	 * @return string The storable CSS.
	 */
	private function css(string $value): string {
		$clean = (string)preg_replace('/url\s*\([^)]*\)/i', 'none', mb_substr($value, 0, self::MAX_STYLE));

		return (string)preg_replace('/@import[^;]*;?/i', '', $clean);
	}

	/**
	 * A navigation's path: the path only, never a query string.
	 *
	 * @param mixed $value The posted path.
	 *
	 * @return string The storable path.
	 */
	private function path(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		$cut = strcspn($value, '?#');

		return mb_substr(substr($value, 0, $cut), 0, self::MAX_PATH);
	}

	/**
	 * A finite non-negative number, else 0.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return int|float The number.
	 */
	private function number(mixed $value): int|float {
		if (is_int($value) === true) {
			return max(0, $value);
		}

		if (is_float($value) === true && is_finite($value) === true) {
			return max(0.0, round($value, 3));
		}

		return 0;
	}
}
