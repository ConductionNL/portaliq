<?php

/**
 * Portaliq Traffic Recording Store.
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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Service\PortalRegisterContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every read and write of the `portalTrafficRecording` schema.
 *
 * A recording takes the ORDINARY object path, not the raw append the
 * events use: it is one object per visit, appended to a few times, and
 * the update in place is what keeps one visit one object. It is readable
 * by instance admins only (the schema says so) and it expires like a raw
 * event, purged by the same aggregation run.
 *
 * Reached by duck typing through the container like TrafficEventStore,
 * so a missing OpenRegister degrades to "nothing stored, nothing read".
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
 */
class TrafficRecordingStore {

	/**
	 * OpenRegister's ObjectService FQCN, resolved lazily from the container.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register the schema lives in.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The recording schema.
	 */
	public const SCHEMA = 'portalTrafficRecording';

	/**
	 * Rows per page when purging.
	 */
	private const PAGE = 200;

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
	 * A portal's recording by its id, or null.
	 *
	 * @param string $portal      The portal slug.
	 * @param string $recordingId The recorder's id for the visit.
	 *
	 * @return array<string, mixed>|null The object, with its `@self`.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
	 */
	public function find(string $portal, string $recordingId): ?array {
		foreach ($this->findAll(filters: ['portal' => $portal, 'recordingId' => $recordingId], limit: 5) as $row) {
			if (($row['portal'] ?? '') === $portal && ($row['recordingId'] ?? '') === $recordingId) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Create or replace a recording.
	 *
	 * @param array<string, mixed> $recording The fields.
	 * @param string|null          $uuid      The existing object's uuid, or null to create.
	 *
	 * @return bool True when saved.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
	 */
	public function save(array $recording, ?string $uuid): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::SCHEMA) === false) {
				return false;
			}

			$objectService->saveObject(
				object: $recording,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic recording save failed', ['portal' => $recording['portal'] ?? '', 'reason' => $e->getMessage()]);

			return false;
		}
	}

	/**
	 * Delete recordings past their `expires`.
	 *
	 * @return int How many were removed.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
	 */
	public function purgeExpired(): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			if (method_exists($objectService, 'purgeExpiredObjectsRaw') === true) {
				return (int)$objectService->purgeExpiredObjectsRaw(self::REGISTER, self::SCHEMA);
			}

			$now = gmdate('Y-m-d\TH:i:s\Z');
			$removed = 0;
			foreach ($this->findAll(filters: ['expires' => ['lt' => $now]], limit: self::PAGE) as $row) {
				$uuid = $this->uuidOf(row: $row);
				if ($uuid === '') {
					continue;
				}

				$objectService->deleteObject(uuid: $uuid, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
				$removed++;
			}

			return $removed;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic recording purge failed', ['reason' => $e->getMessage()]);

			return 0;
		}
	}

	/**
	 * The uuid a stored row carries, or ''.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-session-recording-must-never-hold-text-or-a-typed-value
	 */
	public function uuidOf(array $row): string {
		return trim((string)($row['@self']['uuid'] ?? $row['uuid'] ?? $row['@self']['id'] ?? $row['id'] ?? ''));
	}

	/**
	 * A bounded read of the schema, normalised to arrays.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The most rows wanted.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function findAll(array $filters, int $limit): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::SCHEMA) === false) {
				return [];
			}

			$rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => $limit, 'offset' => 0], _rbac: false, _multitenancy: false);
			if (is_array($rows) === false) {
				return [];
			}

			$out = [];
			foreach ($rows as $row) {
				$out[] = $this->row(row: $row);
			}

			return $out;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic recording read failed', ['reason' => $e->getMessage()]);

			return [];
		}
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
}
