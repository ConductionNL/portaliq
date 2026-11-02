<?php

/**
 * Portaliq Normalised Row Reads
 *
 * Every row of a schema, every page of it, normalised, and a row's id.
 * The using class carries ObjectRowNormaliser, ObjectServiceLookup and
 * PagedObjectReads, a `$logger`, and the constants `REGISTER` and
 * `READ_FAILED` (the warning logged when a read fails).
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

use Throwable;

/**
 * Paged, normalised reads of one schema in the app's register.
 *
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */
trait NormalisedRowReads {
	/**
	 * Fetch every row of a schema in this app's register that matches the
	 * plain filters, every page of it. A failed or unreadable read gives [].
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Plain equality filters, e.g. a status.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	private function findAll(string $schema, array $filters = []): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $this->readEveryPage(objectService: $objectService, register: self::REGISTER, schema: $schema, filters: $filters);
		} catch (Throwable $e) {
			$this->logger->warning(self::READ_FAILED, ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$normalised = [];
		foreach ($rows as $row) {
			$row = $this->normalise(row: $row);
			if ($row !== null) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}//end findAll()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	private function rowId(array $row): string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		if (isset($row['uuid']) === true) {
			return (string)$row['uuid'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true) {
			return (string)($self['id'] ?? $self['uuid'] ?? '');
		}

		return '';
	}//end rowId()
}//end trait
