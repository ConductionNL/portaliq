<?php
/**
 * Portaliq Field Map
 *
 * Where a create action's answers land on the object it creates: the
 * action's `fieldMap`, from answer name to a dotted field path
 * (`onderwerp` → `title`, `periodeVan` → `wooRequest.periodeVan`). Applied
 * after the body's checks, which read the answers under their own names, and
 * reversed when a stored draft is read back into the form.
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
 * @spec openspec/changes/create-action-field-map/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-say-where-each-answer-lands
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Moves a create action's answers to their declared paths, and back.
 *
 * @spec openspec/changes/create-action-field-map/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-say-where-each-answer-lands
 */
class FieldMap {

	private const NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * The body with every mapped answer moved to its path.
	 *
	 * @param array<string, mixed> $action The create action.
	 * @param array<string, mixed> $body   The body, answers under their own names.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/create-action-field-map/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-say-where-each-answer-lands
	 */
	public function apply(array $action, array $body): array {
		foreach ($this->pathsOf(action: $action) as $answer => $path) {
			if (array_key_exists($answer, $body) === false) {
				continue;
			}

			$value = $body[$answer];
			unset($body[$answer]);
			$body = $this->put(body: $body, path: $path, value: $value);
		}

		return $body;
	}//end apply()

	/**
	 * The stored object's data with every mapped answer read back from its
	 * path under its own name.
	 *
	 * @param array<string, mixed> $action The create action.
	 * @param array<string, mixed> $data   The stored object's data.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/create-action-field-map/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-say-where-each-answer-lands
	 */
	public function reverse(array $action, array $data): array {
		foreach ($this->pathsOf(action: $action) as $answer => $path) {
			$found = $this->get(data: $data, path: $path);
			if ($found['found'] === true) {
				$data[$answer] = $found['value'];
			}
		}

		return $data;
	}//end reverse()

	/**
	 * The action's usable map: answer name to path segments.
	 *
	 * @param array<string, mixed> $action The create action.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function pathsOf(array $action): array {
		$paths = [];
		foreach ((array)($action['fieldMap'] ?? []) as $answer => $path) {
			if (is_string($answer) === false || preg_match(self::NAME, $answer) !== 1 || is_string($path) === false) {
				continue;
			}

			$segments = explode('.', $path);
			if (count(array_filter($segments, static fn (string $segment): bool => preg_match(self::NAME, $segment) === 1)) !== count($segments)) {
				continue;
			}

			$paths[$answer] = $segments;
		}

		return $paths;
	}//end pathsOf()

	/**
	 * The body with the value at the path, intermediate objects created.
	 *
	 * @param array<string, mixed> $body  The body.
	 * @param array<int, string>   $path  The path segments.
	 * @param mixed                $value The value.
	 *
	 * @return array<string, mixed>
	 */
	private function put(array $body, array $path, mixed $value): array {
		$head = array_shift($path);
		if ($path === []) {
			$body[$head] = $value;
			return $body;
		}

		$inner = $body[$head] ?? [];
		if (is_array($inner) === false) {
			$inner = [];
		}

		$body[$head] = $this->put(body: $inner, path: $path, value: $value);
		return $body;
	}//end put()

	/**
	 * The value at the path, and whether there is one.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param array<int, string>   $path The path segments.
	 *
	 * @return array{found: bool, value: mixed}
	 */
	private function get(array $data, array $path): array {
		$here = $data;
		foreach ($path as $segment) {
			if (is_array($here) === false || array_key_exists($segment, $here) === false) {
				return ['found' => false, 'value' => null];
			}

			$here = $here[$segment];
		}

		return ['found' => true, 'value' => $here];
	}//end get()
}//end class
