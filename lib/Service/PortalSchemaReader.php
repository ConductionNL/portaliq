<?php

/**
 * Portaliq Portal Schema Reader
 *
 * Resolves an OpenRegister schema DEFINITION (properties) for the portal's
 * schema-driven frontend (the tilburg-woo engine repointed at /portal/api,
 * ADR-063). A schema is metadata, not subject data — but a portal subject may
 * only introspect a schema their contribution manifest references, so the
 * controller gates this by the manifest before calling here. RBAC/multitenancy
 * are bypassed (portal subjects are not NC users; Portaliq is the trusted
 * intermediary), exactly like the object reader/writer.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\PortalSchemaReaderInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves OpenRegister schema definitions by slug for the portal engine.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
 */
class PortalSchemaReader implements PortalSchemaReaderInterface {
	/**
	 * OpenRegister's schema mapper.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister's SchemaMapper.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a schema's serialised definition by slug (RBAC bypassed).
	 *
	 * @param string $slug The schema slug (the manifest already authorised it).
	 *
	 * @return array<string, mixed>|null The schema as an array (with `properties`),
	 *                                   or null when unavailable/not found.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
	 */
	public function readSchema(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$matches = $this->matchesFor(slug: $slug);
		if (is_array($matches) === false || count($matches) === 0) {
			return null;
		}

		return $this->serialised(schema: $matches[0]);
	}//end readSchema()

	/**
	 * What the schema mapper finds for the slug, or null when it is
	 * unavailable or the lookup failed.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
	 */
	private function matchesFor(string $slug): mixed {
		$mapper = $this->schemaMapper();
		if ($mapper === null) {
			return null;
		}

		try {
			return $mapper->findBySlug(slug: $slug, limit: 1, offset: 0, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR schema lookup failed', ['slug' => $slug, 'reason' => $e->getMessage()]);
			return null;
		}
	}//end matchesFor()

	/**
	 * The schema as an array, or null when it does not serialise to one.
	 *
	 * @param mixed $schema The schema entity.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
	 */
	private function serialised(mixed $schema): array|null {
		if (is_object($schema) === true && method_exists($schema, 'jsonSerialize') === true) {
			$data = $schema->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end serialised()

	/**
	 * Resolve OpenRegister's SchemaMapper, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function schemaMapper(): ?object {
		return (new ContainerService(container: $this->container))->get(id: self::SCHEMA_MAPPER);
	}//end schemaMapper()
}//end class
