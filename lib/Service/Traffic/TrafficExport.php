<?php

/**
 * Portaliq Traffic Export.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The daily records as a file: CSV with one row per portal-day-segment
 * and the scalar metrics, or JSON with the records whole.
 *
 * Pure. The CSV carries the scalars only, because a spreadsheet row
 * cannot hold a ranked list; the JSON carries everything the object API
 * would, minus the `@self` envelope, for the caller that wants the lists.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */
class TrafficExport {

	/**
	 * The CSV columns, in order.
	 *
	 * @var string[]
	 */
	public const COLUMNS = [
		'portal',
		'date',
		'segment',
		'pageViews',
		'sessions',
		'visitors',
		'newVisitors',
		'returningVisitors',
		'accounts',
		'engagedSessions',
		'avgEngagementSeconds',
		'bounceRate',
		'conversionRate',
	];

	/**
	 * The formats.
	 *
	 * @var string[]
	 */
	public const FORMATS = ['csv', 'json'];

	/**
	 * The records as CSV.
	 *
	 * @param array<int, array<string, mixed>> $records The daily records.
	 *
	 * @return string The CSV, header first, CRLF lines.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function csv(array $records): string {
		$lines = [$this->line(values: self::COLUMNS)];
		foreach ($records as $record) {
			$values = [];
			foreach (self::COLUMNS as $column) {
				$value = $record[$column] ?? null;
				if ($value === null || is_scalar($value) === false) {
					$values[] = '';
					continue;
				}

				$values[] = is_bool($value) ? (($value === true) ? '1' : '0') : (string)$value;
			}

			$lines[] = $this->line(values: $values);
		}

		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * The records as JSON.
	 *
	 * @param array<int, array<string, mixed>> $records The daily records.
	 *
	 * @return string The JSON array.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function json(array $records): string {
		$out = [];
		foreach ($records as $record) {
			unset($record['@self']);
			$out[] = $record;
		}

		return (string)json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	}

	/**
	 * The file name for a download.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day.
	 * @param string $to      The last day.
	 * @param string $segment The segment, '' for all.
	 * @param string $format  `csv` or `json`.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function fileName(string $portal, string $from, string $to, string $segment, string $format): string {
		$parts = ['traffic', $portal, $from, $to];
		if ($segment !== '') {
			$parts[] = $segment;
		}

		return preg_replace('/[^A-Za-z0-9_.-]/', '-', implode('-', $parts)) . '.' . $format;
	}

	/**
	 * One CSV line, every field quoted as RFC 4180 wants.
	 *
	 * @param string[] $values The fields.
	 *
	 * @return string The line.
	 */
	private function line(array $values): string {
		return implode(',', array_map(
			static fn (string $value): string => (preg_match('/[",\r\n]/', $value) === 1) ? '"' . str_replace('"', '""', $value) . '"' : $value,
			$values
		));
	}
}
