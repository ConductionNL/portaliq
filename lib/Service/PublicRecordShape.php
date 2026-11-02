<?php

/**
 * Portaliq Public Record Shape (site-member-voting-record-and-confidential-papers)
 *
 * Holds a provider's public record to its plain shape.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * A record as `{title, subtitle?, summary[], columns[], rows[], note?}`, plain values only.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */
class PublicRecordShape {
	/**
	 * A record as `{title, subtitle?, summary[], columns[], rows[], note?}`, plain values only.
	 *
	 * @param array<string, mixed> $answer The provider's record.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 */
	public function record(array $answer): array {
		$record = ['title' => $this->text(value: ($answer['title'] ?? null))];
		foreach (['subtitle', 'note'] as $key) {
			$value = $this->text(value: ($answer[$key] ?? null));
			if ($value !== '') {
				$record[$key] = $value;
			}
		}

		$record['summary'] = $this->summary(declared: ($answer['summary'] ?? []));
		$record['columns'] = $this->columns(declared: ($answer['columns'] ?? []));
		$record['rows']    = $this->rows(declared: ($answer['rows'] ?? []), keys: array_column($record['columns'], 'key'));

		return $record;
	}//end record()

	/**
	 * The summary cards that have a label.
	 *
	 * @param mixed $declared The provider's summary.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function summary(mixed $declared): array {
		$summary = [];
		foreach ((array)$declared as $card) {
			if (is_array($card) === true && $this->text(value: ($card['label'] ?? null)) !== '') {
				$summary[] = [
					'label'  => $this->text(value: $card['label']),
					'value'  => $this->text(value: ($card['value'] ?? null)),
					'detail' => $this->text(value: ($card['detail'] ?? null)),
				];
			}
		}

		return $summary;
	}//end summary()

	/**
	 * The columns that have a key.
	 *
	 * @param mixed $declared The provider's columns.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function columns(mixed $declared): array {
		$columns = [];
		foreach ((array)$declared as $column) {
			if (is_array($column) === true && $this->text(value: ($column['key'] ?? null)) !== '') {
				$columns[] = ['key' => $this->text(value: $column['key']), 'label' => $this->text(value: ($column['label'] ?? $column['key']))];
			}
		}

		return $columns;
	}//end columns()

	/**
	 * The rows, held to the columns' keys, with a subject link that stays on the site or is http(s).
	 *
	 * @param mixed              $declared The provider's rows.
	 * @param array<int, string> $keys     The keys of the kept columns.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(mixed $declared, array $keys): array {
		$rows = [];
		foreach ((array)$declared as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$kept = [];
			foreach ($keys as $key) {
				$kept[$key] = $this->text(value: ($row[$key] ?? null));
			}

			if (is_string($row['subjectUrl'] ?? null) === true && preg_match('#^(https?://|/)#', $row['subjectUrl']) === 1) {
				$kept['subjectUrl'] = $row['subjectUrl'];
			}

			$rows[] = $kept;
		}

		return $rows;
	}//end rows()

	/**
	 * A string or number as text, anything else as ''; never markup.
	 *
	 * @param mixed $value A provider's value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
	 */
	public function text(mixed $value): string {
		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim(strip_tags($value));
	}//end text()
}//end class
