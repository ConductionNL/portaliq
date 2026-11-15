<?php

/**
 * Portaliq Paged Schema Read
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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
 * @spec exclude Shared paging helper of the repair steps; the @spec of each step covers what it repairs.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use Throwable;

/**
 * One page of a repair step's schema, as arrays.
 *
 * The using step provides `$context` (PortalRegisterContext), `$logger`,
 * `SCHEMA`, `PAGE` and `EMPTY_PAGE_LOG`, the line logged when the schema
 * cannot be read.
 *
 * @spec exclude Shared paging helper of the repair steps; the @spec of each step covers what it repairs.
 */
trait PagedSchemaRead {
	/**
	 * One page of the step's schema: how many rows OpenRegister returned
	 * (the paging offset moves by that count) and those rows as arrays.
	 * Rows that are not arrays are dropped from `rows` but still counted in
	 * `read`. An absent schema reads nothing.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param int $offset Items to skip.
	 *
	 * @return array{read: int, rows: list<array<string, mixed>>}
	 */
	private function page(object $objectService, int $offset): array {
		try {
			$rows = [];
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::SCHEMA) === true) {
				$rows = $objectService->findAll(config: ['limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
			}
		} catch (Throwable $e) {
			$this->logger->info(self::EMPTY_PAGE_LOG, ['reason' => $e->getMessage()]);

			return ['read' => 0, 'rows' => []];
		}

		$serialised = array_map(
			static function (mixed $row): mixed {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					return $row->jsonSerialize();
				}

				return $row;
			},
			(array)$rows
		);

		return ['read' => count($serialised), 'rows' => array_values(array_filter($serialised, 'is_array'))];
	}//end page()
}//end trait
