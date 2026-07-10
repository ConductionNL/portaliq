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
 * @spec openspec/changes/portal-schema-endpoint/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves OpenRegister schema definitions by slug for the portal engine.
 *
 * @spec openspec/changes/portal-schema-endpoint/tasks.md#T1
 */
class PortalSchemaReader
{
    /**
     * OpenRegister's schema mapper.
     */
    private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container For resolving OpenRegister's SchemaMapper.
     * @param LoggerInterface    $logger    The logger.
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
     * @spec openspec/changes/portal-schema-endpoint/tasks.md#T1
     */
    public function readSchema(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $mapper = $this->schemaMapper();
        if ($mapper === null) {
            return null;
        }

        try {
            $matches = $mapper->findBySlug(slug: $slug, limit: 1, offset: 0, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR schema lookup failed', ['slug' => $slug, 'reason' => $e->getMessage()]);
            return null;
        }

        if (is_array($matches) === false || count($matches) === 0) {
            return null;
        }

        $schema = $matches[0];
        if (is_object($schema) === true && method_exists($schema, 'jsonSerialize') === true) {
            $data = $schema->jsonSerialize();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return null;
    }//end readSchema()

    /**
     * Resolve OpenRegister's SchemaMapper, or null when unavailable.
     *
     * @return object|null
     */
    private function schemaMapper(): ?object
    {
        try {
            $service = $this->container->get(self::SCHEMA_MAPPER);
        } catch (Throwable $e) {
            return null;
        }

        if (is_object($service) === true) {
            return $service;
        }

        return null;
    }//end schemaMapper()
}//end class
