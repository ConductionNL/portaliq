<?php

/**
 * Portaliq Traffic Match.
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
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The one match shape a goal and a funnel step share, and the one place
 * it is evaluated against a stored event.
 *
 * A match is a small map: `pathPrefix` or `pathEquals` against the page
 * path of a page view, `eventName` against any event's name,
 * `fileExtension` against a download's file, `formId` against a submitted
 * form, `term` against a search. Every key present must hold. Each key
 * implies which event it can hold for, so a step that says `pathPrefix`
 * never matches a scroll on that page, and a goal of type `download` never
 * matches a page view of a file's landing page.
 *
 * Pure, and the same definition against the same event always answers the
 * same, which is what keeps the rollup idempotent.
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
 */
class TrafficMatch {

	/**
	 * The keys a match may carry, each with the event it is read on.
	 *
	 * @var array<string, string>
	 */
	public const KEYS = [
		'pathPrefix' => 'page_view',
		'pathEquals' => 'page_view',
		'eventName' => '',
		'fileExtension' => 'file_download',
		'formId' => 'form_submit',
		'term' => 'search',
	];

	/**
	 * The goal types, each with the event it counts.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'page_reached' => 'page_view',
		'event' => '',
		'download' => 'file_download',
		'form_submitted' => 'form_submit',
		'search' => 'search',
	];

	/**
	 * The longest value a match key keeps.
	 */
	private const MAX_VALUE = 256;

	/**
	 * A posted match block as a bounded map of known keys, or null when it
	 * names none.
	 *
	 * @param mixed $value The configured match.
	 *
	 * @return array<string, string>|null The match, or null.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 */
	public function normalise(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$out = [];
		foreach (array_keys(self::KEYS) as $key) {
			$item = $value[$key] ?? null;
			if (is_string($item) === false || trim($item) === '') {
				continue;
			}

			$out[$key] = mb_substr(trim($item), 0, self::MAX_VALUE);
		}

		if ($out === []) {
			return null;
		}

		return $out;
	}

	/**
	 * Whether a stored event satisfies a match, for a goal type or for a
	 * funnel step (type '' means "whatever the keys imply").
	 *
	 * @param array<string, string> $match The normalised match.
	 * @param array<string, mixed>  $event The stored event.
	 * @param string                $type  A goal type, or '' for a step.
	 *
	 * @return bool True when every key holds.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 */
	public function matches(array $match, array $event, string $type = ''): bool {
		$name = (string)($event['name'] ?? '');
		$expected = self::TYPES[$type] ?? '';
		if ($expected !== '' && $name !== $expected) {
			return false;
		}

		foreach ($match as $key => $value) {
			$implied = self::KEYS[$key] ?? null;
			if ($implied === null || ($implied !== '' && $name !== $implied)) {
				return false;
			}

			if ($this->holds(key: $key, value: $value, event: $event) === false) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether one key holds against the event.
	 *
	 * @param string               $key   The match key.
	 * @param string               $value The configured value.
	 * @param array<string, mixed> $event The stored event.
	 *
	 * @return bool True when it holds.
	 */
	private function holds(string $key, string $value, array $event): bool {
		return match ($key) {
			'pathPrefix' => str_starts_with($this->path(event: $event), $value),
			'pathEquals' => $this->path(event: $event) === rtrim($value, '/') || $this->path(event: $event) === $value,
			'eventName' => (string)($event['name'] ?? '') === $value,
			'fileExtension' => $this->extension(event: $event) === strtolower(ltrim($value, '.')),
			'formId' => (string)($event['params']['formId'] ?? '') === $value,
			'term' => str_contains(mb_strtolower($this->term(event: $event)), mb_strtolower($value)),
			default => false,
		};
	}

	/**
	 * The event's page path, with a trailing slash removed so `/contact`
	 * and `/contact/` are one page.
	 *
	 * @param array<string, mixed> $event The stored event.
	 *
	 * @return string The path.
	 */
	private function path(array $event): string {
		$path = (string)($event['pagePath'] ?? '');
		if ($path === '') {
			$path = $this->pathOf(url: (string)($event['pageLocation'] ?? ''));
		}

		if (strlen($path) > 1) {
			$path = rtrim($path, '/');
		}

		return $path;
	}

	/**
	 * The downloaded file's extension, lower-cased, from the file name or
	 * the link.
	 *
	 * @param array<string, mixed> $event The stored event.
	 *
	 * @return string The extension without its dot, or ''.
	 */
	private function extension(array $event): string {
		$file = (string)($event['fileName'] ?? ($event['params']['file_name'] ?? ''));
		if ($file === '') {
			$file = $this->pathOf(url: (string)($event['linkUrl'] ?? ($event['params']['link_url'] ?? '')));
		}

		$dot = strrpos($file, '.');
		if ($dot === false) {
			return '';
		}

		return strtolower(substr($file, $dot + 1));
	}

	/**
	 * The path component of a URL, or '' when it has none.
	 *
	 * @param string $url The URL.
	 *
	 * @return string The path.
	 */
	private function pathOf(string $url): string {
		$parsed = parse_url($url, PHP_URL_PATH);
		if (is_string($parsed) === false) {
			return '';
		}

		return $parsed;
	}

	/**
	 * The search term the event carries.
	 *
	 * @param array<string, mixed> $event The stored event.
	 *
	 * @return string The term, or ''.
	 */
	private function term(array $event): string {
		return (string)($event['searchTerm'] ?? ($event['params']['search_term'] ?? ($event['params']['searchTerm'] ?? '')));
	}
}
