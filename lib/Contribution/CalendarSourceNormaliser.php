<?php

/**
 * Portaliq Calendar Source Normaliser (contribution-record-page)
 *
 * One source of a `calendar` block: the collection it reads, the fields that
 * hold each item's start, end and title (or those of a list it expands), a
 * kind label, and the record scope. Presentation only: the collection must
 * resolve against the trust-filtered collections of the same contribution.
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
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates one calendar source, fail-closed.
 *
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */
class CalendarSourceNormaliser {
	/**
	 * One calendar source, or null when its collection does not resolve or it
	 * names no start and title field (its own, or its expanded list's).
	 *
	 * @param mixed $source The declared source.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-must-show-dated-rows-as-a-list-and-a-month
	 */
	public function source(mixed $source, array $collectionIds): ?array {
		if (is_array($source) === false || in_array(($source['collection'] ?? null), $collectionIds, true) === false) {
			return null;
		}

		$out = $this->withDates(source: $source, entry: ['collection' => $source['collection']]);
		if ($out === null) {
			return null;
		}

		if ($this->isName(value: ($source['kind'] ?? null)) === true) {
			$out['kind'] = $source['kind'];
		}

		$only = $this->only(declared: ($source['only'] ?? null));
		if ($only !== null) {
			$out['only'] = $only;
		}

		return (new RecordScopeNormaliser())->scope(declared: $source, entry: $out);
	}//end source()

	/**
	 * The source with its own date fields, or with its `expand` list and that
	 * list's date fields; null when neither is complete.
	 *
	 * @param array<string, mixed> $source The declared source.
	 * @param array<string, mixed> $entry The normalised source so far.
	 *
	 * @return array<string, mixed>|null
	 */
	private function withDates(array $source, array $entry): ?array {
		$expand = ($source['expand'] ?? null);
		if (is_array($expand) === false) {
			$fields = $this->dateFields(declared: $source);
			if ($fields === null) {
				return null;
			}

			return array_merge($entry, $fields);
		}

		$fields = $this->dateFields(declared: $expand);
		if ($fields === null || $this->isName(value: ($expand['field'] ?? null)) === false) {
			return null;
		}

		$entry['expand'] = array_merge(['field' => $expand['field']], $fields);
		return $entry;
	}//end withDates()

	/**
	 * The start field, the title field or fixed title (a row without a title
	 * value takes the fixed one), and the optional end field; null without a
	 * start or any title.
	 *
	 * @param array<string, mixed> $declared The declared source or expansion.
	 *
	 * @return array<string, string>|null
	 */
	private function dateFields(array $declared): ?array {
		$titled = ($this->isName(value: ($declared['titleField'] ?? null)) === true || $this->isName(value: ($declared['title'] ?? null)) === true);
		if ($this->isName(value: ($declared['startField'] ?? null)) === false || $titled === false) {
			return null;
		}

		$out = ['startField' => $declared['startField']];
		foreach (['titleField', 'title', 'endField'] as $key) {
			if ($this->isName(value: ($declared[$key] ?? null)) === true) {
				$out[$key] = $declared[$key];
			}
		}

		return $out;
	}//end dateFields()

	/**
	 * The `only` rule, `{field, in}`: a row counts only when its field holds
	 * one of the listed values. Null when it names no field or no value.
	 *
	 * @param mixed $declared The declared rule.
	 *
	 * @return array{field: string, in: array<int, string>}|null
	 */
	private function only(mixed $declared): ?array {
		if (is_array($declared) === false || $this->isName(value: ($declared['field'] ?? null)) === false) {
			return null;
		}

		$values = array_values(array_filter((array)($declared['in'] ?? []), fn ($value): bool => $this->isName(value: $value)));
		if ($values === []) {
			return null;
		}

		return ['field' => $declared['field'], 'in' => $values];
	}//end only()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isName()
}//end class
