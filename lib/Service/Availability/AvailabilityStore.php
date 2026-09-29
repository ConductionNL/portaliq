<?php

/**
 * Portaliq availability store
 *
 * Reads and writes the availability records (`portalAvailabilityDaily`,
 * `portalAvailabilityOutage`) in OpenRegister.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Availability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Availability;

use OCA\Portaliq\Service\PortalRegisterContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The availability records, through OpenRegister's object service with RBAC
 * off: the probe job runs without a user, and the schemas are readable by
 * administrators only.
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */
class AvailabilityStore {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	public const DAILY_SCHEMA = 'portalAvailabilityDaily';

	public const OUTAGE_SCHEMA = 'portalAvailabilityOutage';

	/**
	 * Rows per page, and the most one read returns.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalRegisterContext $context Points the shared service at this app's schemas.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
	) {
	}//end __construct()

	/**
	 * A portal's daily records between two dates, inclusive, keyed by date.
	 *
	 * @param string $portal The portal slug.
	 * @param string $from The first date, YYYY-MM-DD.
	 * @param string $until The last date, YYYY-MM-DD.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function dailyBetween(string $portal, string $from, string $until): array {
		$days = [];
		foreach ($this->findAll(schema: self::DAILY_SCHEMA, filters: ['portal' => $portal, 'date' => ['gte' => $from, 'lte' => $until]]) as $row) {
			if (($row['portal'] ?? '') === $portal && isset($row['date']) === true) {
				$days[(string)$row['date']] = $row;
			}
		}

		ksort($days);

		return $days;
	}//end dailyBetween()

	/**
	 * A portal's outages that started between two moments.
	 *
	 * @param string $portal The portal slug.
	 * @param string $from ISO 8601 start.
	 * @param string $until ISO 8601 end.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function outagesBetween(string $portal, string $from, string $until): array {
		$rows = $this->findAll(schema: self::OUTAGE_SCHEMA, filters: ['portal' => $portal, 'startedAt' => ['gte' => $from, 'lte' => $until]]);
		$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['portal'] ?? '') === $portal));
		usort($rows, static fn (array $first, array $second): int => strcmp((string)($first['startedAt'] ?? ''), (string)($second['startedAt'] ?? '')));

		return $rows;
	}//end outagesBetween()

	/**
	 * A portal's open outage, or null.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 */
	public function openOutage(string $portal): ?array {
		foreach ($this->findAll(schema: self::OUTAGE_SCHEMA, filters: ['portal' => $portal]) as $row) {
			if (($row['portal'] ?? '') === $portal && trim((string)($row['endedAt'] ?? '')) === '') {
				return $row;
			}
		}

		return null;
	}//end openOutage()

	/**
	 * Create or update one record.
	 *
	 * @param string $schema DAILY_SCHEMA or OUTAGE_SCHEMA.
	 * @param array<string, mixed> $row The record; its `uuid` (or `@self.id`) updates in place.
	 *
	 * @return bool True when saved.
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 */
	public function save(string $schema, array $row): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		$uuid = $this->uuidOf(row: $row);
		unset($row['@self'], $row['uuid'], $row['id']);

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return false;
			}

			$objectService->saveObject(
				object: $row,
				register: self::REGISTER,
				schema: $schema,
				uuid: ($uuid === '' ? null : $uuid),
				_rbac: false,
				_multitenancy: false
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: availability save failed', ['schema' => $schema, 'reason' => $e->getMessage()]);

			return false;
		}
	}//end save()

	/**
	 * Delete every daily record before a date and every outage that started
	 * before it.
	 *
	 * @param string $cutoff The first date kept, YYYY-MM-DD.
	 *
	 * @return int How many records were removed.
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-thirteen-months-are-kept-and-no-more-req-oar-003
	 */
	public function purgeBefore(string $cutoff): int {
		$removed = 0;
		foreach ([self::DAILY_SCHEMA => 'date', self::OUTAGE_SCHEMA => 'startedAt'] as $schema => $field) {
			foreach ($this->findAll(schema: $schema, filters: [$field => ['lt' => $cutoff]]) as $row) {
				if ((string)($row[$field] ?? '') < $cutoff && $this->delete(schema: $schema, uuid: $this->uuidOf(row: $row)) === true) {
					$removed++;
				}
			}
		}

		return $removed;
	}//end purgeBefore()

	/**
	 * Delete one record.
	 *
	 * @param string $schema The schema.
	 * @param string $uuid The record's uuid.
	 *
	 * @return bool
	 */
	private function delete(string $schema, string $uuid): bool {
		$objectService = $this->objectService();
		if ($objectService === null || $uuid === '') {
			return false;
		}

		try {
			$this->context->apply(objectService: $objectService, schemaSlug: $schema);
			$objectService->deleteObject(uuid: $uuid, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: availability delete failed', ['schema' => $schema, 'reason' => $e->getMessage()]);

			return false;
		}
	}//end delete()

	/**
	 * A record's uuid, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return string
	 */
	private function uuidOf(array $row): string {
		return (string)($row['@self']['uuid'] ?? $row['uuid'] ?? $row['@self']['id'] ?? $row['id'] ?? '');
	}//end uuidOf()

	/**
	 * One read of a schema, normalised to arrays.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return [];
			}

			$rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => self::PAGE, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: availability read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);

			return [];
		}

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end findAll()

	/**
	 * OpenRegister's object service, or null when it is not installed.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
