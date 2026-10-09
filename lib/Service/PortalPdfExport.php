<?php

/**
 * Portaliq Portal PDF Export
 *
 * Turns the rows a resident can already see into a PDF through OpenRegister's
 * sandboxed renderer. Portaliq carries no PDF library.
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
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the columns and the text rows from the collection's own declaration,
 * so the file holds what the screen holds and nothing else.
 *
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
 */
class PortalPdfExport {

	/**
	 * What render() answers when OpenRegister refuses the row count.
	 *
	 * @var string
	 */
	public const TOO_LARGE = 'too_large';

	/**
	 * OpenRegister's export service, by name only.
	 *
	 * @var string
	 */
	public const EXPORT_SERVICE = 'OCA\\OpenRegister\\Service\\ExportService';

	/**
	 * The rows-based render method OpenRegister publishes for callers that fetched their own rows.
	 *
	 * @var string
	 */
	public const RENDER_METHOD = 'renderRowsToPdf';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister's ExportService.
	 * @param LoggerInterface $logger Records a failed render.
	 * @param string $serviceClass The service's class name; a test names a fake.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $serviceClass = self::EXPORT_SERVICE,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister's rows renderer is there.
	 *
	 * @return bool True when the service resolves and has the render method.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t01
	 */
	public function available(): bool {
		return $this->service() !== null;
	}//end available()

	/**
	 * Render rows to a PDF. Null when OpenRegister cannot, TOO_LARGE when it
	 * refuses the row count.
	 *
	 * @param string $title The document title.
	 * @param array<int, array{key: string, label: string}> $columns The columns.
	 * @param array<int, array<string, string>> $rows The text rows, keyed by column key.
	 *
	 * @return string|null The PDF bytes, TOO_LARGE, or null on any other failure.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
	 */
	public function render(string $title, array $columns, array $rows): ?string {
		$service = $this->service();
		if ($service === null) {
			return null;
		}

		try {
			$bytes = $service->{self::RENDER_METHOD}($title, $columns, $rows);
		} catch (Throwable $e) {
			if (basename(str_replace('\\', '/', $e::class)) === 'ExportTooLargeException') {
				return self::TOO_LARGE;
			}

			$this->logger->warning('Portaliq: the PDF could not be rendered', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_string($bytes) === false || str_starts_with($bytes, '%PDF') === false) {
			return null;
		}

		return $bytes;
	}//end render()

	/**
	 * The columns of a list export: the collection's `columns` with their
	 * labels, else its projected `fields`.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, array<string, mixed>> `{key, label, valueLabels?}` per column.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
	 */
	public function listColumns(array $collection): array {
		$columns = [];
		foreach ((array)($collection['columns'] ?? []) as $column) {
			if (is_array($column) === true && is_string($column['field'] ?? null) === true) {
				$entry = ['key' => $column['field'], 'label' => (string)($column['label'] ?? $column['field'])];
				if (is_array($column['valueLabels'] ?? null) === true) {
					$entry['valueLabels'] = $column['valueLabels'];
				}

				$columns[] = $entry;
			}
		}

		if ($columns !== []) {
			return $columns;
		}

		return $this->fieldColumns(fields: $collection['fields'] ?? null);
	}//end listColumns()

	/**
	 * The columns of a record export: the detail fields, else the projected fields.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, array<string, mixed>> `{key, label}` per column.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
	 */
	public function recordColumns(array $collection): array {
		$labels = [];
		foreach ($this->listColumns(collection: $collection) as $column) {
			$labels[$column['key']] = $column;
		}

		$detail = $collection['detail']['fields'] ?? null;
		if (is_array($detail) === true && $detail !== []) {
			$columns = [];
			foreach ($detail as $field) {
				if (is_string($field) === true) {
					$columns[] = ($labels[$field] ?? ['key' => $field, 'label' => $field]);
				}
			}

			return $columns;
		}

		return $this->listColumns(collection: $collection);
	}//end recordColumns()

	/**
	 * The text rows for the columns: only the columns' keys, each value as the screen shows it.
	 *
	 * @param array<int, array<string, mixed>> $columns The columns.
	 * @param array<int, array<string, mixed>> $rows The rows from the scoped read.
	 *
	 * @return array<int, array<string, string>> The rows.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
	 */
	public function textRows(array $columns, array $rows): array {
		$text = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$line = [];
			foreach ($columns as $column) {
				$line[$column['key']] = $this->cell(value: ($row[$column['key']] ?? null), labels: (array)($column['valueLabels'] ?? []));
			}

			$text[] = $line;
		}

		return $text;
	}//end textRows()

	/**
	 * The title: the collection's label, the organisation and the date.
	 *
	 * @param string $label The collection's label.
	 * @param string $organisation The organisation's name or id.
	 * @param string $date The export date, `Y-m-d`.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t04
	 */
	public function title(string $label, string $organisation, string $date): string {
		return implode(', ', array_filter([$label, $organisation, $date], static fn (string $part): bool => $part !== ''));
	}//end title()

	/**
	 * Columns from a list of field keys.
	 *
	 * @param mixed $fields The projected fields.
	 *
	 * @return array<int, array<string, string>> The columns.
	 */
	private function fieldColumns(mixed $fields): array {
		$columns = [];
		foreach ((array)$fields as $field) {
			if (is_string($field) === true && $field !== '') {
				$columns[] = ['key' => $field, 'label' => $field];
			}
		}

		return $columns;
	}//end fieldColumns()

	/**
	 * One cell as text, the way the screen shows it.
	 *
	 * @param mixed $value The raw value.
	 * @param array<string, string> $labels A column's value labels.
	 *
	 * @return string The text.
	 */
	private function cell(mixed $value, array $labels): string {
		if (is_bool($value) === true) {
			$raw   = $value;
			$value = 'false';
			if ($raw === true) {
				$value = 'true';
			}
		}

		if (is_array($value) === true) {
			return implode(', ', array_map(fn ($item): string => $this->cell(value: $item, labels: $labels), $value));
		}

		if (is_scalar($value) === false) {
			return '';
		}

		$raw = (string)$value;
		return ($labels[$raw] ?? $raw);
	}//end cell()

	/**
	 * OpenRegister's export service when it is there and can render rows.
	 *
	 * @return object|null The service.
	 */
	private function service(): ?object {
		if (class_exists($this->serviceClass) === false) {
			return null;
		}

		try {
			$service = $this->container->get($this->serviceClass);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: the export service cannot be built', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($service) === true && method_exists($service, self::RENDER_METHOD) === true) {
			return $service;
		}

		return null;
	}//end service()
}//end class
