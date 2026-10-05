<?php

/**
 * Paged Object Reads
 *
 * Reads every row of one OpenRegister schema that matches a filter, page by
 * page in a stable order, instead of the first 500 rows in whatever order the
 * store returns them. A capped read loses rows silently once a schema grows
 * past the cap: news that never reaches a feed, a message thread that cannot
 * be found, a sign-up count that stops at 500. The callers keep their own
 * checks on every row; the filter only narrows what is read.
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
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use RuntimeException;

/**
 * Every matching row of one schema, read page by page.
 *
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */
trait PagedObjectReads {
	/**
	 * Rows per page.
	 */
	private const PAGED_READ_SIZE = 500;

	/**
	 * A hard stop, so a store that ignores the offset can never loop forever.
	 */
	private const PAGED_READ_MAX_PAGES = 200;

	/**
	 * The stable order the pages are read in: oldest first, the uuid breaking
	 * a tie, so an offset never skips or repeats a row between two pages.
	 */
	private const PAGED_READ_ORDER = ['_created' => 'ASC', '_uuid' => 'ASC'];

	/**
	 * Every row of a schema that matches the filters, as the store returns
	 * them. The register and schema are set again before every page, because
	 * the shared ObjectService may have been moved by another read in between.
	 *
	 * A read that fails throws, so the caller keeps its own failure answer.
	 * Running into the hard stop throws as well: an answer that silently
	 * stops part-way is exactly what this read replaces.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters The filters, as OpenRegister takes them.
	 *
	 * @return array<int, mixed>|null The raw rows, or null when a page is not a list.
	 *
	 * @throws RuntimeException When the store keeps answering full pages past the hard stop.
	 *
	 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	private function readEveryPage(object $objectService, string $register, string $schema, array $filters = []): ?array {
		$rows = [];
		for ($page = 0; $page < self::PAGED_READ_MAX_PAGES; $page++) {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
			$batch = $objectService->findAll(
				config: [
					'filters' => $filters,
					'limit' => self::PAGED_READ_SIZE,
					'offset' => ($page * self::PAGED_READ_SIZE),
					'sort' => self::PAGED_READ_ORDER,
				],
				_rbac: false,
				_multitenancy: false
			);

			if (is_array($batch) === false) {
				return null;
			}

			array_push($rows, ...array_values($batch));
			if (count($batch) < self::PAGED_READ_SIZE) {
				return $rows;
			}
		}

		throw new RuntimeException('more than ' . (self::PAGED_READ_MAX_PAGES * self::PAGED_READ_SIZE) . ' rows in ' . $schema);
	}//end readEveryPage()
}//end trait
