<?php

/**
 * Portaliq Collection Columns Normaliser (contribution-manifest-v3)
 *
 * The `columns` half of the fail-closed collection presentation config.
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
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates and sanitises the `columns` of a collection, fail-closed.
 *
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T1
 */
class CollectionColumnsNormaliser {
	/**
	 * Allowed column render kinds; anything else normalises to `text`.
	 */
	private const RENDER_KINDS = ['text', 'date', 'datetime', 'badge', 'currency', 'boolean', 'link', 'qr', 'user'];

	/**
	 * Constructor.
	 *
	 * @param ManifestValueNormaliser $values The shared value-level primitives.
	 */
	public function __construct(
		private readonly ManifestValueNormaliser $values,
	) {
	}//end __construct()

	/**
	 * Keep only well-formed `columns`; drop the key otherwise.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T1
	 */
	public function normaliseColumns(array $collection): array {
		if (array_key_exists('columns', $collection) === false) {
			return $collection;
		}

		if (is_array($collection['columns']) === false) {
			unset($collection['columns']);
			return $collection;
		}

		$columns = [];
		foreach ($collection['columns'] as $column) {
			$entry = $this->normaliseColumn(column: $column);
			if ($entry !== null) {
				$columns[] = $entry;
			}
		}

		$collection['columns'] = $columns;
		return $collection;
	}//end normaliseColumns()

	/**
	 * Sanitise ONE column entry, or null when it carries no usable `field`.
	 *
	 * Keeps `field`, a string `label`, the `render` kind and a well-formed
	 * `valueLabels` map; every other key is dropped.
	 *
	 * @param mixed $column The declared column.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
	 */
	private function normaliseColumn(mixed $column): ?array {
		if (is_array($column) === false) {
			return null;
		}

		$field = ($column['field'] ?? '');
		if (is_string($field) === false || $field === '') {
			return null;
		}

		$entry = ['field' => $field];
		if (isset($column['label']) === true && is_string($column['label']) === true) {
			$entry['label'] = $column['label'];
		}

		$entry['render'] = $this->values->oneOf(value: ($column['render'] ?? null), allowed: self::RENDER_KINDS, default: 'text');
		// The words of a link or a code, 1 to 60 characters, on a link or qr column only
		// (link-field-qr-code).
		$entry = $this->withLinkLabel(entry: $entry, source: $column);

		// How each value reads ("approved" as "Goedgekeurd"); the cell falls
		// back to the raw value for one the app did not label.
		return (new ValueLabelsNormaliser())->apply(entry: $entry, source: $column);
	}//end normaliseColumn()

	/**
	 * Keep a trimmed `linkLabel` of 1 to 60 characters on a `link` or `qr`
	 * column; drop it on any other kind and when it is not that.
	 *
	 * @param array<string, mixed> $entry  The column so far.
	 * @param array<string, mixed> $source The declared column.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/link-field-qr-code/tasks.md#t1
	 */
	private function withLinkLabel(array $entry, array $source): array {
		$label = ($source['linkLabel'] ?? null);
		if (in_array($entry['render'], ['link', 'qr'], true) === false || is_string($label) === false) {
			return $entry;
		}

		$label = trim($label);
		if ($label !== '' && mb_strlen($label) <= 60) {
			$entry['linkLabel'] = $label;
		}

		return $entry;
	}//end withLinkLabel()
}//end class
