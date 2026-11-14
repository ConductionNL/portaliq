<?php

/**
 * Portaliq Staff Object Store (hermiq-ai-tooling)
 *
 * Reads and writes one portaliq object AS THE SIGNED-IN STAFF MEMBER, through
 * OpenRegister's ObjectService with RBAC and multitenancy on. This is the
 * same path the staff UI takes when it saves a page, a menu or a portal
 * through OpenRegister's objects API, so a curated MCP tool can never write
 * what that staff member could not write by hand.
 *
 * @category Mcp
 * @package  OCA\Portaliq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
 */

declare(strict_types=1);

namespace OCA\Portaliq\Mcp;

use OCA\Portaliq\Service\PortalRegisterContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One portaliq object read or written under the caller's own RBAC.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
 */
class StaffObjectStore {

	/**
	 * OpenRegister's object service, resolved lazily (OpenRegister may be absent).
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register every portaliq schema lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container Resolves OpenRegister's ObjectService.
	 * @param PortalRegisterContext $context   Points the ObjectService at a portaliq schema.
	 * @param LoggerInterface       $logger    Records a failed read or write.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContext $context,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One object by id, as the caller may see it; null when it is absent,
	 * hidden from the caller, or OpenRegister fails.
	 *
	 * @param string $schema The portaliq schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null The object.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	public function find(string $schema, string $id): ?array {
		$objectService = $this->objectService(schema: $schema);
		if ($objectService === null || $id === '') {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: $schema,
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: staff tool read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->normalise(row: $entity);
	}//end find()

	/**
	 * Save an object as the caller: an update when `$id` is given, else a
	 * create. Null when RBAC refuses or OpenRegister fails.
	 *
	 * @param string               $schema The portaliq schema slug.
	 * @param array<string, mixed> $object The whole object to store.
	 * @param string|null          $id     The id to update, or null to create.
	 *
	 * @return array<string, mixed>|null The saved object.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	public function save(string $schema, array $object, ?string $id = null): ?array {
		$objectService = $this->objectService(schema: $schema);
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: $schema,
				uuid: $id,
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: staff tool write failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->normalise(row: $saved);
	}//end save()

	/**
	 * The ObjectService pointed at a portaliq schema; null without OpenRegister.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return object|null The service.
	 */
	private function objectService(string $schema): ?object {
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OpenRegister unavailable for a staff tool', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($objectService) === false || $this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
			return null;
		}

		return $objectService;
	}//end objectService()

	/**
	 * An OpenRegister row as a plain array.
	 *
	 * @param mixed $row An entity, an array or null.
	 *
	 * @return array<string, mixed>|null The row.
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
}//end class
