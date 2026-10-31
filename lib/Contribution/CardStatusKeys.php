<?php

/**
 * Portaliq Card Status Keys (card-status-today)
 *
 * A card may say where its record stands today, from rows of another
 * collection of the same contribution: a guardian's child card reads
 * "Ziek gemeld" when one of the guardian's own absence reports covers today,
 * "Op school" on any other school day, and nothing at a weekend. The app
 * declares the lookup; nothing is stored, the site derives the chip when it
 * draws the card.
 *
 * The block's `status` is kept only whole and well formed: a collection of
 * this contribution, the field names it projects, a label and a tone per
 * outcome. Anything else drops the key, so a half declaration draws no chip
 * rather than a wrong one.
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
 * @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises the `status` lookup of a `cards` collection block.
 *
 * @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today
 */
class CardStatusKeys {
	/**
	 * The tones a chip may wear, and the app words that mean one of them.
	 */
	private const TONES = [
		'neutral'  => 'neutral',
		'success'  => 'success',
		'positive' => 'success',
		'warning'  => 'warning',
		'error'    => 'error',
		'negative' => 'error',
	];

	/**
	 * The longest label kept.
	 */
	private const MAX_LABEL = 60;

	/**
	 * The most values the `only` filter may list.
	 */
	private const MAX_VALUES = 10;

	/**
	 * The block's `status`, or [] when it is absent or malformed.
	 *
	 * @param array<string, mixed>             $block         The declared block.
	 * @param array<int, string>               $collectionIds The collections of this contribution.
	 * @param array<int, array<string, mixed>> $collections   The sanitised collections.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today
	 */
	public function keys(array $block, array $collectionIds, array $collections): array {
		$status = ($block['status'] ?? null);
		if (($block['display'] ?? null) !== 'cards' || is_array($status) === false) {
			return [];
		}

		$collection = ($status['collection'] ?? null);
		if (is_string($collection) === false || in_array($collection, $collectionIds, true) === false) {
			return [];
		}

		$fields = $this->fields(status: $status, projected: $this->projected(collections: $collections, id: $collection));
		$label = $this->label(value: ($status['label'] ?? null));
		if ($fields === null || $label === null) {
			return [];
		}

		$out = ['collection' => $collection] + $fields + [
			'label' => $label,
			'tone'  => $this->tone(value: ($status['tone'] ?? null)),
		];

		$other = $this->label(value: ($status['otherLabel'] ?? null));
		if ($other !== null) {
			$out['otherLabel'] = $other;
			$out['otherTone'] = $this->tone(value: ($status['otherTone'] ?? null));
		}

		$only = $this->only(value: ($status['only'] ?? null), projected: $this->projected(collections: $collections, id: $collection));
		if ($only !== null) {
			$out['only'] = $only;
		}

		$out['schoolDaysOnly'] = (($status['schoolDaysOnly'] ?? false) === true);

		return ['status' => $out];
	}//end keys()

	/**
	 * The three field names, each a name the collection projects, or null.
	 *
	 * @param array<string, mixed>    $status    The declared status.
	 * @param array<int, string>|null $projected The collection's projected fields, null when it names none.
	 *
	 * @return array<string, string>|null
	 */
	private function fields(array $status, ?array $projected): ?array {
		$out = [];
		foreach (['matchField', 'fromField', 'toField'] as $key) {
			$field = ($status[$key] ?? null);
			if ($this->isField(field: $field, projected: $projected) === false) {
				return null;
			}

			$out[$key] = $field;
		}

		return $out;
	}//end fields()

	/**
	 * The `only` filter: a projected field and a short list of string values.
	 *
	 * @param mixed                   $value     The declared filter.
	 * @param array<int, string>|null $projected The collection's projected fields.
	 *
	 * @return array{field: string, in: array<int, string>}|null
	 */
	private function only(mixed $value, ?array $projected): ?array {
		if (is_array($value) === false || $this->isField(field: ($value['field'] ?? null), projected: $projected) === false) {
			return null;
		}

		$values = array_values(array_filter((array)($value['in'] ?? []), static fn ($item): bool => is_string($item) === true && $item !== ''));
		if ($values === []) {
			return null;
		}

		return ['field' => $value['field'], 'in' => array_slice($values, 0, self::MAX_VALUES)];
	}//end only()

	/**
	 * Whether a value names a field the collection projects.
	 *
	 * @param mixed                   $field     The declared name.
	 * @param array<int, string>|null $projected The projected fields, null when the collection names none.
	 *
	 * @return bool
	 */
	private function isField(mixed $field, ?array $projected): bool {
		if (is_string($field) === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $field) !== 1) {
			return false;
		}

		return $projected === null || in_array($field, $projected, true) === true;
	}//end isField()

	/**
	 * The projected fields of one collection, or null when it names none.
	 *
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 * @param string                           $id          The collection's id.
	 *
	 * @return array<int, string>|null
	 */
	private function projected(array $collections, string $id): ?array {
		foreach ($collections as $candidate) {
			if (($candidate['id'] ?? null) === $id && is_array($candidate['fields'] ?? null) === true) {
				return array_values(array_filter($candidate['fields'], 'is_string'));
			}
		}

		return null;
	}//end projected()

	/**
	 * A trimmed, short label, or null.
	 *
	 * @param mixed $value The declared label.
	 *
	 * @return string|null
	 */
	private function label(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$label = trim($value);
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
			return null;
		}

		return $label;
	}//end label()

	/**
	 * A tone the chip knows, `neutral` for anything else.
	 *
	 * @param mixed $value The declared tone.
	 *
	 * @return string
	 */
	private function tone(mixed $value): string {
		if (is_string($value) === false || isset(self::TONES[$value]) === false) {
			return 'neutral';
		}

		return self::TONES[$value];
	}//end tone()
}//end class
