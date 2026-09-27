<?php

/**
 * Message Store
 *
 * The raw OpenRegister plumbing (save/find-all/normalise/id-extraction) for
 * `messageThread`/`message` objects, split out of `InAppMessagingLeaf` so
 * that class stays about PARTICIPATION POLICY (delegated to
 * {@see MessageThreadAccessGuard}) plus orchestration, not persistence
 * mechanics — the same separation this app's other services already use
 * (`PortalObjectReader`/`PortalObjectWriter`).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Messaging
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
 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
 */
class MessageStore {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save an object into a schema, returning its id, or null on failure.
	 * When `$uuid` is given, OpenRegister UPDATES that row instead of
	 * creating a new one.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object to save.
	 * @param string|null $uuid The existing id to update, or null to create.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
	 */
	public function save(string $schema, array $object, ?string $uuid = null): ?string {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: messaging save failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		$normalised = $this->normalise(row: $saved);
		if ($normalised === null) {
			return null;
		}

		return $this->rowId(row: $normalised);
	}//end save()

	/**
	 * Fetch every row of a schema, unfiltered, normalised.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
	 */
	public function findAll(string $schema): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: messaging read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
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
	 * @return string|null
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
	 */
	public function rowId(array $row): ?string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		if (isset($row['uuid']) === true) {
			return (string)$row['uuid'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true && (isset($self['id']) === true || isset($self['uuid']) === true)) {
			return (string)($self['id'] ?? $self['uuid']);
		}

		return null;
	}//end rowId()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
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
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
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
