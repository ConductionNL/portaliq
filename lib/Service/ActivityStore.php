<?php

/**
 * Activity Store
 *
 * The one place the activity services talk to OpenRegister
 * (extracurricular-activity-offer): read every row of an activity schema,
 * save one, and name a row's identity. The event services each carry their
 * own copy of this plumbing; the activity services share this one, so their
 * rules can be tested against a fake store.
 *
 * SECURITY: reads and writes run with RBAC and multitenancy off, the same as
 * every portaliq service, because portaliq is the trusted scoper: the caller
 * has already proven who may see or change what. A read that fails returns
 * null, never an empty list, so a count built on it can fail closed.
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
 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the activity schemas in the portaliq register.
 *
 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
 */
class ActivityStore {
	/**
	 * The activity schema.
	 */
	public const OFFER = 'activityOffer';

	/**
	 * The sign-up schema.
	 */
	public const SIGNUP = 'activitySignup';

	/**
	 * The attendance schema.
	 */
	public const ATTENDANCE = 'activityAttendance';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * This app's register.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The most rows one read returns, the same bound the event feed uses.
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister's ObjectService.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every row of one activity schema, normalised, or null when the read
	 * failed. Null is not an empty list: a caller counting places must refuse
	 * rather than promise a place it cannot count.
	 *
	 * @param string $schema One of the schema constants.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function rows(string $schema): ?array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(
				config: ['filters' => [], 'limit' => self::READ_LIMIT, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: activity read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		$normalised = [];
		foreach ($rows as $row) {
			$row = $this->normalise(row: $row);
			if ($row !== null) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}//end rows()

	/**
	 * One row of a schema by its id, uuid or slug, or null.
	 *
	 * @param string $schema One of the schema constants.
	 * @param string $id The id, uuid or slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function find(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach (($this->rows(schema: $schema) ?? []) as $row) {
			if (in_array($id, $this->keys(row: $row), true) === true) {
				return $row;
			}
		}

		return null;
	}//end find()

	/**
	 * Save one row: create it, or update it when an id is given.
	 *
	 * @param string $schema One of the schema constants.
	 * @param array<string, mixed> $data The object data, without the `@self` envelope.
	 * @param string $id The id to update, or '' to create.
	 *
	 * @return array<string, mixed>|null The saved row, or null on failure.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function save(string $schema, array $data, string $id = ''): ?array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		unset($data['@self']);
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		try {
			$saved = $objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: activity write failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->normalise(row: $saved);
	}//end save()

	/**
	 * The row's primary id: `id`, else `uuid`, else the `@self` id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when the row carries none.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function idOf(array $row): string {
		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * Every name a row answers to: its id, its uuid and its slug. Seed rows
	 * point at each other by slug, rows written by the services by id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function keys(array $row): array {
		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$keys = [];
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null), ($self['slug'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				$keys[] = (string)$candidate;
			}
		}

		return array_values(array_unique($keys));
	}//end keys()

	/**
	 * Normalise an OpenRegister result (array or entity) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
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
	 * OpenRegister's ObjectService, or null when it is not available.
	 *
	 * @return object|null
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
