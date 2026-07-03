<?php

/**
 * Portaliq Portal Object Writer
 *
 * Creates objects in a contribution collection through OpenRegister on behalf of
 * an authenticated subject (the write side of ADR-046 actions). The subject
 * reference and tenant are injected server-side from the validated session —
 * never taken from the client payload — so a subject can only ever create
 * records owned by itself. Like the reader, it bypasses OR's NC-user RBAC /
 * multitenancy (portal subjects are not NC users) because Portaliq is the
 * trusted intermediary that scopes the write.
 *
 * Verified against OpenRegister 0.2.17: `ObjectService::saveObject($data, extend,
 * $register, $schema, uuid, _rbac, _multitenancy, ...)` takes register/schema as
 * dedicated arguments and returns the saved ObjectEntity.
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
 * @spec openspec/changes/supplier-portal/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Subject-scoped writer over OpenRegister for portal actions.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T06
 */
class PortalObjectWriter
{
    /**
     * OpenRegister's object service.
     */
    private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container For resolving OpenRegister services.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create an object owned by the subject.
     *
     * @param string               $register     The register slug/id.
     * @param string               $schema       The schema slug.
     * @param string               $scopeField   The field stamped with the subject ref.
     * @param string               $subjectRef   The server-derived subject reference.
     * @param string               $organisation The tenant to stamp (may be empty).
     * @param array<string, mixed> $data         The client-supplied fields (already whitelisted).
     *
     * @return array<string, mixed>|null The created object, or null on failure.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T06
     */
    public function createObject(
        string $register,
        string $schema,
        string $scopeField,
        string $subjectRef,
        string $organisation,
        array $data
    ): ?array {
        $objectService = $this->objectService();
        if ($objectService === null) {
            return null;
        }

        // Server-side ownership stamps ALWAYS win over any client-supplied value.
        if ($scopeField !== '') {
            $data[$scopeField] = $subjectRef;
        }

        if ($organisation !== '') {
            $data['organisation'] = $organisation;
        }

        try {
            $saved = $objectService->saveObject(
                object: $data,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR write failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return null;
        }

        return $this->normalise(row: $saved);
    }//end createObject()

    /**
     * Normalise an OpenRegister result (array or ObjectEntity) to an array.
     *
     * @param mixed $row The saved object.
     *
     * @return array<string, mixed>|null
     */
    private function normalise(mixed $row): ?array
    {
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
    private function objectService(): ?object
    {
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
