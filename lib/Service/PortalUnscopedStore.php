<?php

/**
 * Portaliq Unscoped Store
 *
 * Reads and deletes with NO subject boundary — the operator-facing paths.
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
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The unscoped reads and deletes, kept deliberately apart.
 *
 * THE NAME IS THE WARNING. `PortalObjectReader` exists to enforce a per-row
 * ownership boundary and every method on it takes a subject; putting a method
 * with no boundary among methods whose whole purpose IS the boundary is how one
 * gets reached for by mistake. These are the paths with no subject to scope by
 * — a background sweep over a portal's own traffic, an operator reading their
 * own portal's figures — and they live under a name nobody will confuse with
 * the scoped reader.
 *
 * They were briefly on `PortalObjectWriter`, next to `countObjects()`, until
 * phpmd flagged that class at complexity 55 against a threshold of 50. The
 * split is what the measurement asked for and what the naming wanted anyway.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalUnscopedStore {

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';


	/**
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Read rows from a register/schema matching a set of property filters.
	 *
	 * Fails closed to an empty list. A traffic summary that 500s because the
	 * register is unreachable is worse than one that reports nothing yet.
	 *
	 * @param string               $register The register slug/id.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  Property filters.
	 * @param int                  $limit    The most rows to return.
	 *
	 * @return array<int, array<string, mixed>> The rows, or [].
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function readObjects(string $register, string $schema, array $filters = [], int $limit = 5000): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => $limit]);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
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
	}//end readObjects()


	/**
	 * Delete rows by id.
	 *
	 * NARROW ON PURPOSE: it takes explicit ids and has no filter parameter at
	 * all. A delete that accepts a filter is one typo away from emptying a
	 * table, and the only caller — the retention sweep — has already decided
	 * exactly which rows are expired through a pure, separately tested
	 * function. Nothing here re-decides.
	 *
	 * Each delete is independent. One row that refuses must not abort the
	 * sweep, or a single stuck row would keep a portal permanently past its
	 * retention window.
	 *
	 * @param string             $register The register slug/id.
	 * @param string             $schema   The schema slug.
	 * @param array<int, string> $ids      The ids to delete.
	 *
	 * @return int How many were deleted.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function deleteObjects(string $register, string $schema, array $ids): int {
		$objectService = $this->objectService();
		if ($objectService === null || $ids === []) {
			return 0;
		}

		$deleted = 0;
		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id === '') {
				continue;
			}

			try {
				$objectService->deleteObject(uuid: $id, register: $register, schema: $schema);
				$deleted++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Portaliq: OR delete failed',
					['schema' => $schema, 'reason' => $e->getMessage()]
				);
			}
		}

		return $deleted;
	}//end deleteObjects()


	/**
	 * An OpenRegister row as a plain array, or null.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null The array form.
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()


	/**
	 * OpenRegister's object service, or null when unavailable.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()


}//end class
