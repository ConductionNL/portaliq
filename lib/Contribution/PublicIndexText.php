<?php

/**
 * Portaliq Public Index Text (portal-public-catalogue)
 *
 * The text and list cleaning of a public index item.
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
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Cleans the text and the lists of a public index item.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
 */
class PublicIndexText {
	/**
	 * The longest title, kind, label or short text.
	 */
	private const MAX_SHORT = 200;

	/**
	 * The most cells one row may carry.
	 */
	private const MAX_CELLS = 12;

	/**
	 * The most meta lines and facets one item carries.
	 */
	private const MAX_PARTS = 6;

	/**
	 * The meta lines: the short ones, at most MAX_PARTS.
	 *
	 * @param mixed $declared The entry's meta.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function meta(mixed $declared): array {
		return array_slice($this->shortList(values: (array)$declared), 0, self::MAX_PARTS);
	}//end meta()

	/**
	 * The facets: a short label to one short value or a list of them.
	 *
	 * @param mixed $declared The entry's facets.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function facets(mixed $declared): array {
		$facets = [];
		if (is_array($declared) === false) {
			return $facets;
		}

		foreach ($declared as $label => $value) {
			$label  = $this->short(value: $label);
			$values = $this->shortList(values: (array)$value);
			if ($label === null || $values === [] || count($facets) >= self::MAX_PARTS) {
				continue;
			}

			$facets[$label] = array_slice(array_values(array_unique($values)), 0, self::MAX_PARTS);
		}

		return $facets;
	}//end facets()

	/**
	 * The cells of a table row: a column key and its text. A key that is not
	 * a plain word, or a value that is not text, is dropped.
	 *
	 * @param mixed $declared The entry's cells.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function cells(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$out = [];
		foreach ($declared as $key => $value) {
			$text = $this->short(value: $value);
			if (is_string($key) === true && preg_match('/^[a-z][A-Za-z0-9]{0,29}$/', $key) === 1 && $text !== null && count($out) < self::MAX_CELLS) {
				$out[$key] = $text;
			}
		}

		return $out;
	}//end cells()
	/**
	 * The values of a list that are short one-line strings.
	 *
	 * @param array<int|string, mixed> $values The values.
	 *
	 * @return array<int, string>
	 */
	private function shortList(array $values): array {
		$out = [];
		foreach ($values as $value) {
			$short = $this->short(value: $value);
			if ($short !== null) {
				$out[] = $short;
			}
		}

		return $out;
	}//end shortList()
	/**
	 * A trimmed one-line string of at most MAX_SHORT characters, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function short(mixed $value): ?string {
		if (is_int($value) === true) {
			$value = (string)$value;
		}

		$text = $this->text(value: $value, max: self::MAX_SHORT);
		if ($text === null) {
			return null;
		}

		return preg_replace('/\s+/', ' ', $text);
	}//end short()
	/**
	 * A trimmed string of at most `max` characters with no markup, or null.
	 *
	 * @param mixed $value The value.
	 * @param int   $max   The longest.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function text(mixed $value, int $max): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$value = trim(strip_tags($value));
		if ($value === '') {
			return null;
		}

		if (mb_strlen($value) > $max) {
			$value = rtrim(mb_substr($value, 0, $max - 1)) . '…';
		}

		return $value;
	}//end text()
}//end class
