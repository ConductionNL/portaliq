<?php

/**
 * Portaliq Traffic Event Store.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Service\PortalRegisterContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every read and write of the two traffic schemas, in one place.
 *
 * TWO WRITE PATHS, ONE OF WHICH IS THE POINT. Raw events go through
 * OpenRegister's `appendObjectsRaw()`, which skips the audit trail, RBAC,
 * validation, events and the search index. A traffic collector that ran the
 * full object pipeline would write an audit row for every page view, which
 * is both a cost and a second copy of the data under a different retention.
 * When the running OpenRegister predates that entry point, `saveObjects()`
 * with everything switched off is the fallback, and it is logged ONCE so an
 * operator can see which path they are on. Nothing is silently dropped.
 *
 * The daily rollup takes the ORDINARY path on purpose: it is a record
 * someone may subscribe to, export or wire a flow to, and it is rewritten
 * ninety-six times a day at most, so the pipeline's cost is irrelevant.
 *
 * OpenRegister is reached by duck typing through the container, the same
 * way every other reader in this app does it, so a missing OpenRegister
 * degrades to "nothing stored, nothing read" rather than a fatal.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- every read and
 * write of the two traffic schemas lives here on purpose (the class
 * comment says why); the segment reads and the delete that
 * portal-traffic-reporting added put it one over the threshold, and a
 * second store would be a second place for the raw-versus-ordinary
 * write decision.
 */
class TrafficEventStore {

	/**
	 * OpenRegister's ObjectService FQCN, resolved lazily from the container.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register both schemas live in.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The raw event schema.
	 */
	public const EVENT_SCHEMA = 'portalTrafficEvent';

	/**
	 * The daily rollup schema.
	 */
	public const DAILY_SCHEMA = 'portalTrafficDaily';

	/**
	 * Rows per page when reading raw events.
	 */
	private const PAGE = 1000;

	/**
	 * The most rows one read will page through. A day of a very busy portal
	 * is tens of thousands; a hundred thousand is a bound, not a target.
	 */
	private const MAX_ROWS = 100000;

	/**
	 * Whether the "which write path" line has been logged this process.
	 *
	 * @var bool
	 */
	private bool $pathLogged = false;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface       $logger    The logger.
	 * @param PortalRegisterContext $context   Points the shared ObjectService at this app's schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
	) {
	}

	/**
	 * Append accepted events, raw.
	 *
	 * @param array<int, array<string, mixed>> $records The normalised events.
	 *
	 * @return int How many were written.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-page-view-must-be-reported-by-the-client-never-inferred-from-a-server-side-read
	 */
	public function append(array $records): int {
		if ($records === []) {
			return 0;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			if (method_exists($objectService, 'appendObjectsRaw') === true) {
				$this->logPath(path: 'appendObjectsRaw');

				return (int)$objectService->appendObjectsRaw($records, self::REGISTER, self::EVENT_SCHEMA);
			}

			$this->logPath(path: 'saveObjects');
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::EVENT_SCHEMA) === false) {
				return 0;
			}

			$saved = $objectService->saveObjects(
				objects: $records,
				register: self::REGISTER,
				schema: self::EVENT_SCHEMA,
				_rbac: false,
				_multitenancy: false,
				validation: false,
				events: false,
				enrich: false,
				_audit: false
			);

			if (is_array($saved) === true) {
				return count($saved);
			}

			return count($records);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic append failed', ['reason' => $e->getMessage()]);

			return 0;
		}
	}

	/**
	 * Delete raw events past their `expires`.
	 *
	 * @return int How many were removed, or 0 when the platform cannot say.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
	 */
	public function purgeExpired(): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			if (method_exists($objectService, 'purgeExpiredObjectsRaw') === true) {
				return (int)$objectService->purgeExpiredObjectsRaw(self::REGISTER, self::EVENT_SCHEMA);
			}

			// The fallback deletes one by one through the ordinary path. Slow,
			// but retention is a promise and "the platform is old" is not a
			// reason to break it.
			$now = gmdate('Y-m-d\TH:i:s\Z');
			$rows = $this->findAll(schema: self::EVENT_SCHEMA, filters: ['expires' => ['lt' => $now]], limit: self::PAGE);
			$removed = 0;
			foreach ($rows as $row) {
				$uuid = (string)($row['@self']['uuid'] ?? $row['uuid'] ?? $row['@self']['id'] ?? $row['id'] ?? '');
				if ($uuid === '') {
					continue;
				}

				$objectService->deleteObject(
					uuid: $uuid,
					register: self::REGISTER,
					schema: self::EVENT_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$removed++;
			}

			return $removed;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic purge failed', ['reason' => $e->getMessage()]);

			return 0;
		}
	}

	/**
	 * A portal's raw events in a window, oldest first.
	 *
	 * @param string $portal The portal slug.
	 * @param string $from   Inclusive lower bound, ISO 8601.
	 * @param string $to     Exclusive upper bound, ISO 8601.
	 *
	 * @return array<int, array<string, mixed>> The events.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function eventsBetween(string $portal, string $from, string $to): array {
		return $this->findAll(
			schema: self::EVENT_SCHEMA,
			filters: ['portal' => $portal, 'occurredAt' => ['gte' => $from, 'lt' => $to]],
			limit: self::MAX_ROWS,
			order: ['occurredAt' => 'ASC']
		);
	}

	/**
	 * Every raw event received since a moment, across portals.
	 *
	 * Used to find which portal-days the aggregation must recompute.
	 *
	 * @param string $since Inclusive lower bound on `receivedAt`, ISO 8601.
	 *
	 * @return array<int, array<string, mixed>> The events.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function receivedSince(string $since): array {
		return $this->findAll(
			schema: self::EVENT_SCHEMA,
			filters: ['receivedAt' => ['gte' => $since]],
			limit: self::MAX_ROWS
		);
	}

	/**
	 * The existing "all sessions" rollup for a portal-day, or null.
	 *
	 * @param string $portal The portal slug.
	 * @param string $date   The UTC day, YYYY-MM-DD.
	 *
	 * @return array<string, mixed>|null The rollup object.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function findDaily(string $portal, string $date): ?array {
		foreach ($this->findDailyRows(portal: $portal, date: $date) as $row) {
			if (trim((string)($row['segment'] ?? '')) === '') {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Every rollup of a portal-day: the "all sessions" one and one per
	 * segment (portal-traffic-reporting).
	 *
	 * Read by portal and date only, and told apart by `segment` here: a
	 * record written before segments existed has no such property, and a
	 * filter on an absent property is not a filter the object API promises
	 * to answer the same way on every version.
	 *
	 * @param string $portal The portal slug.
	 * @param string $date   The UTC day, YYYY-MM-DD.
	 *
	 * @return array<int, array<string, mixed>> The rollups.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	public function findDailyRows(string $portal, string $date): array {
		return $this->findAll(schema: self::DAILY_SCHEMA, filters: ['portal' => $portal, 'date' => $date], limit: self::PAGE);
	}

	/**
	 * A portal's rollups over a span of days, for one segment, oldest first.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day, inclusive.
	 * @param string $to      The last day, inclusive.
	 * @param string $segment The segment id, '' for all sessions.
	 *
	 * @return array<int, array<string, mixed>> The rollups.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function dailyBetween(string $portal, string $from, string $to, string $segment = ''): array {
		$rows = $this->findAll(
			schema: self::DAILY_SCHEMA,
			filters: ['portal' => $portal, 'date' => ['gte' => $from, 'lte' => $to]],
			limit: self::MAX_ROWS,
			order: ['date' => 'ASC']
		);

		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => trim((string)($row['segment'] ?? '')) === $segment
		));
	}

	/**
	 * Remove one rollup: a segment's record after the segment was deleted.
	 *
	 * @param string $uuid The rollup's uuid.
	 *
	 * @return bool True when removed.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	public function deleteDaily(string $uuid): bool {
		$objectService = $this->objectService();
		if ($objectService === null || $uuid === '') {
			return false;
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::DAILY_SCHEMA) === false) {
				return false;
			}

			$objectService->deleteObject(
				uuid: $uuid,
				register: self::REGISTER,
				schema: self::DAILY_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic rollup delete failed', ['uuid' => $uuid, 'reason' => $e->getMessage()]);

			return false;
		}
	}

	/**
	 * Create or replace a rollup.
	 *
	 * With a uuid the save is an UPDATE of that object, which is what makes
	 * recomputing a day idempotent: the numbers are replaced, never added.
	 *
	 * @param array<string, mixed> $rollup The rollup fields.
	 * @param string|null          $uuid   The existing object's uuid, or null to create.
	 *
	 * @return bool True when saved.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function saveDaily(array $rollup, ?string $uuid): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::DAILY_SCHEMA) === false) {
				return false;
			}

			$objectService->saveObject(
				object: $rollup,
				register: self::REGISTER,
				schema: self::DAILY_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic rollup save failed', ['portal' => $rollup['portal'] ?? '', 'reason' => $e->getMessage()]);

			return false;
		}
	}

	/**
	 * A paged read of one schema, normalised to arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The most rows wanted.
	 * @param array<string, string> $order  Sort, field => ASC|DESC.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function findAll(string $schema, array $filters, int $limit, array $order = []): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return [];
			}

			return $this->pages(objectService: $objectService, schema: $schema, filters: $filters, limit: $limit, order: $order);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);

			return [];
		}
	}

	/**
	 * Page through a query until it runs dry or the limit is reached.
	 *
	 * @param object                $objectService OpenRegister's object service.
	 * @param string                $schema        The schema slug.
	 * @param array<string, mixed>  $filters       The filters.
	 * @param int                   $limit         The most rows wanted.
	 * @param array<string, string> $order         Sort, field => ASC|DESC.
	 *
	 * @return array<int, array<string, mixed>> The rows, as arrays.
	 */
	private function pages(object $objectService, string $schema, array $filters, int $limit, array $order): array {
		$out = [];
		for ($offset = 0; $offset < $limit; $offset += self::PAGE) {
			$config = ['filters' => $filters, 'limit' => min(self::PAGE, $limit - $offset), 'offset' => $offset];
			if ($order !== []) {
				$config['sort'] = $order;
			}

			// The shared service's schema ref is re-applied before EVERY
			// page: a nested read elsewhere in the process may have moved
			// it, and a page read against the wrong schema is an empty
			// page, not an error.
			$this->context->apply(objectService: $objectService, schemaSlug: $schema);
			$rows = $objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
			if (is_array($rows) === false || $rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$out[] = $this->row(row: $row);
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $out;
	}

	/**
	 * One row as an array, whether OpenRegister handed back an array or an
	 * entity.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row's fields.
	 */
	private function row(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}

	/**
	 * OpenRegister's object service, or null when it is not installed.
	 *
	 * @return object|null The service.
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
	}

	/**
	 * Say once per process which write path is in use.
	 *
	 * @param string $path The method name.
	 *
	 * @return void
	 */
	private function logPath(string $path): void {
		if ($this->pathLogged === true) {
			return;
		}

		$this->pathLogged = true;
		if ($path === 'appendObjectsRaw') {
			$this->logger->info('Portaliq: traffic events are written through ObjectService::appendObjectsRaw');

			return;
		}

		$this->logger->warning(
			'Portaliq: this OpenRegister has no appendObjectsRaw; traffic events take saveObjects '
			. 'with audit, validation and events off. Upgrade OpenRegister for the raw path.'
		);
	}
}
