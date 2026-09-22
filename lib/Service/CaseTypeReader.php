<?php

/**
 * Portaliq Case Type Reader
 *
 * Reads one case type object through OpenRegister so the portal can ask it
 * what a citizen may change on a case of that type. A case type is a
 * declaration, not a citizen's record: it belongs to no subject, carries no
 * personal data, and the portal reads it only to render what the case app
 * already decided. So there is no ownership boundary to enforce here, unlike
 * every read on the citizen's own rows.
 *
 * Fail closed on everything: no OpenRegister, an error, a missing type or a
 * row that is not an array all read as null, and the resolver above turns a
 * null type into an empty writable set with a closed window.
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a case type object by id, fail closed.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CaseTypeReader {
	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

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
	 * Read one case type by id.
	 *
	 * @param string $register The register the case type lives in.
	 * @param string $schema The schema the case type lives in.
	 * @param string $id The case type id, taken from the case's own type field.
	 *
	 * @return array<string, mixed>|null The case type, or null.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function readCaseType(string $register, string $schema, string $id): ?array {
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: case type read failed',
				['schema' => $schema, 'reason' => $e->getMessage()]
			);
			return null;
		}

		return $this->asArray(entity: $entity);
	}//end readCaseType()

	/**
	 * The entity as a plain array, or null when it cannot be one.
	 *
	 * OpenRegister answers with an array on some paths and an object with
	 * jsonSerialize() on others, so the shape has to be narrowed before a
	 * caller can read a field off it. Split out of readCaseType() so the read
	 * itself is about reading and this is about the shape that comes back.
	 *
	 * @param mixed $entity Whatever OpenRegister returned.
	 *
	 * @return array<string, mixed>|null
	 */
	private function asArray(mixed $entity): ?array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$data = $entity->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end asArray()

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
