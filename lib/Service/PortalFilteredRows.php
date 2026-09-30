<?php

/**
 * Portal Filtered Rows
 *
 * The query side of a `via` read and the declared-filter check of a read by
 * id, split out of PortalObjectReader (change via-read-scoped-query). In the
 * reverse mode a via read asks OpenRegister for the subject's own rows, one
 * query per verified target in the collection's own scope field, instead of
 * the first page of the whole schema; the collection's declared `filter`
 * narrows the query in both modes, and a row fetched by id must satisfy it
 * too. The scope field always wins over the filter. The per-row membership
 * and tenant checks stay in PortalObjectReader and remain the boundary.
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
 * @spec openspec/changes/via-read-scoped-query/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the subject-scoped outer query of a via read and checks a row
 * against a collection's declared filter.
 *
 * @spec openspec/changes/via-read-scoped-query/tasks.md#T1
 */
class PortalFilteredRows {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask OpenRegister for the outer rows of a via read.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $schema The target schema (register and schema are set by the caller).
	 * @param string $scopeField The collection's own scope field.
	 * @param array<string, true> $targets The verified target set.
	 * @param string $match 'id' (forward) or 'scopeField' (reverse).
	 * @param int $limit Maximum rows to return.
	 * @param array<string, mixed> $filter The declared narrowing filter.
	 *
	 * @return array<int, mixed>|null The raw rows, or null when the read failed.
	 *
	 * @spec openspec/changes/via-read-scoped-query/tasks.md#T1
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per
	 * part of the declared collection, as in PortalObjectReader.
	 */
	public function outerRows(
		object $objectService,
		string $schema,
		string $scopeField,
		array $targets,
		string $match,
		int $limit,
		array $filter,
	): ?array {
		$queries = [$this->filters(filter: $filter, scopeField: '', value: '')];
		if ($match === 'scopeField' && $scopeField !== '') {
			$queries = [];
			foreach (array_keys($targets) as $target) {
				$queries[] = $this->filters(filter: $filter, scopeField: $scopeField, value: (string)$target);
			}
		}

		$rows = [];
		try {
			foreach ($queries as $filters) {
				$remaining = ($limit - count($rows));
				if ($remaining <= 0) {
					break;
				}

				$page = $objectService->findAll(config: ['filters' => $filters, 'limit' => $remaining, 'offset' => 0], _rbac: false, _multitenancy: false);
				$rows = $this->withoutDuplicates(rows: $rows, page: (array)$page);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $rows;
	}//end outerRows()

	/**
	 * Whether a row satisfies the declared filter. The scope field is left
	 * to the scope checks, exactly as the list query lets it win.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $filter The declared filter.
	 * @param string $scopeField The collection's scope field.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/via-read-scoped-query/tasks.md#T2
	 */
	public function matchesFilter(array $row, array $filter, string $scopeField): bool {
		foreach ($filter as $key => $expected) {
			if (is_string($key) === false || $key === '' || $key === $scopeField) {
				continue;
			}

			if ($this->valueAt(row: $row, path: $key) !== $expected) {
				return false;
			}
		}

		return true;
	}//end matchesFilter()

	/**
	 * The OpenRegister filters for one query: the declared filter, with the
	 * scope field set last so a filter can never widen the read.
	 *
	 * @param array<string, mixed> $filter The declared filter.
	 * @param string $scopeField The scope field, or '' for none.
	 * @param string $value The scope value.
	 *
	 * @return array<string, mixed>
	 */
	private function filters(array $filter, string $scopeField, string $value): array {
		$filters = [];
		foreach ($filter as $key => $expected) {
			if (is_string($key) === true && $key !== '') {
				$filters[$key] = $expected;
			}
		}

		if ($scopeField !== '' && $value !== '') {
			$filters[$scopeField] = $value;
		}

		return $filters;
	}//end filters()

	/**
	 * Append a page of rows, skipping a row already read by an earlier query
	 * (a list-valued scope field can name two of the subject's targets).
	 *
	 * @param array<int, mixed> $rows The rows read so far.
	 * @param array<int|string, mixed> $page The next page.
	 *
	 * @return array<int, mixed>
	 */
	private function withoutDuplicates(array $rows, array $page): array {
		$seen = [];
		foreach ($rows as $row) {
			foreach ($this->ids(row: $row) as $id) {
				$seen[$id] = true;
			}
		}

		foreach ($page as $row) {
			$ids = $this->ids(row: $row);
			if (array_intersect_key(array_flip($ids), $seen) !== []) {
				continue;
			}

			foreach ($ids as $id) {
				$seen[$id] = true;
			}

			$rows[] = $row;
		}

		return $rows;
	}//end withoutDuplicates()

	/**
	 * A row's own identifiers, for an array or a serialisable entity.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<int, string>
	 */
	private function ids(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		$self = (array)($row['@self'] ?? []);
		$candidates = [($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)];

		return array_values(array_unique(array_filter($candidates, static fn ($id): bool => is_string($id) === true && $id !== '')));
	}//end ids()

	/**
	 * The value at a dot path, or null.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $path The dot path.
	 *
	 * @return mixed
	 */
	private function valueAt(array $row, string $path): mixed {
		$value = $row;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}//end valueAt()
}//end class
