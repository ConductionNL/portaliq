<?php

/**
 * Portaliq Public Index Kinds (editor-blocks-read-public-app-data)
 *
 * An app that offers a public index MAY also say what each kind of item can
 * be narrowed by and drawn as, through an optional provider method
 * `getPublicIndexKinds(string $portal): array`. Each entry is
 * `{type, label, categories[], filters{label: values[]}, columns[{key, label}]}`.
 * The editor offers a page block only these categories, filters and columns,
 * so portaliq keeps no list of an app's kinds of its own.
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
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Holds an app's declared index kinds to a fixed shape.
 *
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
 */
class PublicIndexKinds {
	/**
	 * The contract method an app may implement.
	 */
	public const METHOD = 'getPublicIndexKinds';

	/**
	 * The contract method that resolves the value `visitor` for a signed-in
	 * person: `getPublicIndexVisitor(string $portal, array $subject): array`
	 * answering `{filter label: values[]}`.
	 */
	public const VISITOR_METHOD = 'getPublicIndexVisitor';

	private const MAX_KINDS = 20;

	private const MAX_LIST = 50;

	private const MAX_TEXT = 120;

	/**
	 * Keep each well-formed kind, drop the rest.
	 *
	 * @param string $appId   The app that answered.
	 * @param mixed  $answer  What the app returned.
	 *
	 * @return array<int, array<string, mixed>> Kinds: app, type, label, categories, filters, columns.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function kinds(string $appId, mixed $answer): array {
		if (is_array($answer) === false) {
			return [];
		}

		$out  = [];
		$seen = [];
		foreach (array_slice(array_values($answer), 0, self::MAX_KINDS) as $entry) {
			$type = null;
			if (is_array($entry) === true) {
				$type = ($entry['type'] ?? null);
			}

			if (is_string($type) === false || preg_match('/^[a-z][A-Za-z0-9]{0,39}$/', $type) !== 1 || isset($seen[$type]) === true) {
				continue;
			}

			$seen[$type] = true;
			$out[]       = [
				'app'        => $appId,
				'type'       => $type,
				'label'      => ($this->text(value: ($entry['label'] ?? null)) ?? $type),
				'categories' => $this->texts(values: ($entry['categories'] ?? null)),
				'filters'    => $this->filters(declared: ($entry['filters'] ?? null)),
				'columns'    => $this->columns(declared: ($entry['columns'] ?? null)),
			];
		}

		return $out;
	}//end kinds()

	/**
	 * The filter values a signed-in person resolves to, held to text.
	 *
	 * @param mixed $answer What the app returned.
	 *
	 * @return array<string, array<int, string>> Filter label to values.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function visitorValues(mixed $answer): array {
		return $this->filters(declared: $answer);
	}//end visitorValues()

	/**
	 * @param mixed $declared Filter label to values.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function filters(mixed $declared): array {
		$out = [];
		if (is_array($declared) === false) {
			return $out;
		}

		foreach ($declared as $label => $values) {
			$label  = $this->text(value: $label);
			$values = $this->texts(values: $values);
			if ($label !== null && $values !== [] && count($out) < self::MAX_LIST) {
				$out[$label] = $values;
			}
		}

		return $out;
	}//end filters()

	/**
	 * @param mixed $declared A list of `{key, label}`.
	 *
	 * @return array<int, array{key: string, label: string}>
	 */
	private function columns(mixed $declared): array {
		$out = [];
		if (is_array($declared) === false) {
			return $out;
		}

		foreach (array_slice(array_values($declared), 0, self::MAX_LIST) as $column) {
			$key   = null;
			$label = null;
			if (is_array($column) === true) {
				$key   = ($column['key'] ?? null);
				$label = $this->text(value: ($column['label'] ?? null));
			}

			if (is_string($key) === true && preg_match('/^[a-z][A-Za-z0-9]{0,29}$/', $key) === 1 && $label !== null) {
				$out[] = ['key' => $key, 'label' => $label];
			}
		}

		return $out;
	}//end columns()

	/**
	 * @param mixed $values A list of text.
	 *
	 * @return array<int, string>
	 */
	private function texts(mixed $values): array {
		$out = [];
		if (is_array($values) === false) {
			return $out;
		}

		foreach (array_slice(array_values($values), 0, self::MAX_LIST) as $value) {
			$text = $this->text(value: $value);
			if ($text !== null && in_array($text, $out, true) === false) {
				$out[] = $text;
			}
		}

		return $out;
	}//end texts()

	/**
	 * @param mixed $value Any value.
	 *
	 * @return string|null Plain trimmed text, or null.
	 */
	private function text(mixed $value): ?string {
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

		return mb_substr(preg_replace('/\s+/', ' ', $value), 0, self::MAX_TEXT);
	}//end text()
}//end class
