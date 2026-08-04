<?php

/**
 * Portaliq Portal File Writer
 *
 * Attaches an uploaded file to a contribution object through OpenRegister's
 * existing object-file store, on behalf of an authenticated portal subject
 * (the file-upload block, ADR-063). OpenRegister already owns the per-object
 * file endpoints (`POST /api/objects/{register}/{schema}/{id}/files*`) and the
 * underlying `FileService::addFile()`; this writer reuses that service in-process
 * so portal uploads land in the SAME object folder as internal ones — no new
 * storage path, no duplicated file plumbing.
 *
 * SECURITY: like the object reader/writer, this bypasses OpenRegister's NC-user
 * RBAC (portal subjects are not NC users) because Portaliq is the trusted
 * intermediary. The per-subject OWNERSHIP check is the caller's responsibility
 * and MUST have passed (the controller re-reads the object through the scoped
 * reader and only calls this when the subject owns it) — this class never sees a
 * subject it should refuse, it only performs the attach once ownership is proven.
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
 * @spec openspec/changes/portal-file-upload/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Attaches subject-uploaded files to a contribution object via OpenRegister.
 *
 * @spec openspec/changes/portal-file-upload/tasks.md#T1
 */
class PortalFileWriter
{
    /**
     * OpenRegister's file service.
     */
    private const FILE_SERVICE = 'OCA\\OpenRegister\\Service\\FileService';

    /**
     * OpenRegister's object service — used to resolve an object UUID to a full
     * ObjectEntity before attaching a file (see attachFile()).
     */
    private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container For resolving OpenRegister's FileService.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Attach an uploaded file to an object the subject already owns.
     *
     * The caller MUST have re-verified ownership through the scoped reader before
     * calling this (a foreign or non-existent id must never reach here). The file
     * lands in the object's OpenRegister folder via `FileService::addFile()`, RBAC
     * bypassed (Portaliq is the trusted scoper). Fails closed to null on any OR
     * error.
     *
     * @param string $register The register slug/id.
     * @param string $schema   The schema slug.
     * @param string $id       The owned object's id (ownership already verified).
     * @param string $fileName The sanitised upload filename.
     * @param string $content  The raw file bytes.
     *
     * @return array<string, mixed>|null The attached file's metadata, or null on failure.
     *
     * @spec openspec/changes/portal-file-upload/tasks.md#T1
     */
    public function attachFile(
        string $register,
        string $schema,
        string $id,
        string $fileName,
        string $content
    ): ?array {
        $fileService = $this->fileService();
        if ($fileService === null) {
            return null;
        }

        // Resolve the object UUID to a full ObjectEntity FIRST. OpenRegister's
        // addFile() takes `getObjectFolder()`'s entity-input branch (which
        // resolves the register from the entity itself) only when handed an
        // ObjectEntity; handed a bare string id + null registerId it falls to
        // `createObjectFolderById(string, registerId: null)` and throws "Failed
        // to create file because no objectEntity or registerId given" — so every
        // portal upload 502'd. Mirrors OpenRegister's own SharesProvider.
        $entity = $this->resolveObjectEntity(register: $register, schema: $schema, id: $id);
        if ($entity === null) {
            return null;
        }

        try {
            $file = $fileService->addFile(
                objectEntity: $entity,
                fileName: $fileName,
                content: $content,
                _schema: $schema,
                _register: $register
            );
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR file attach failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return null;
        }

        return $this->normalise(file: $file, fileName: $fileName);
    }//end attachFile()

    /**
     * Shape the OpenRegister File result down to safe metadata for the client.
     *
     * @param mixed  $file     The OpenRegister File (or array).
     * @param string $fileName The upload filename (fallback).
     *
     * @return array<string, mixed>
     */
    private function normalise(mixed $file, string $fileName): array
    {
        $name = $fileName;
        $size = null;
        $id   = null;
        if (is_object($file) === true) {
            if (method_exists($file, 'getName') === true) {
                $name = (string) $file->getName();
            }

            if (method_exists($file, 'getSize') === true) {
                $size = $file->getSize();
            }

            if (method_exists($file, 'getId') === true) {
                $id = $file->getId();
            }
        }

        return ['id' => $id, 'name' => $name, 'size' => $size];
    }//end normalise()

    /**
     * Resolve OpenRegister's FileService, or null when unavailable.
     *
     * @return object|null
     */
    private function fileService(): ?object
    {
        try {
            $service = $this->container->get(self::FILE_SERVICE);
        } catch (Throwable $e) {
            return null;
        }

        if (is_object($service) === true) {
            return $service;
        }

        return null;
    }//end fileService()

    /**
     * Resolve an object UUID to a full OpenRegister ObjectEntity via the object
     * service, or null when it cannot be resolved. Ownership has already been
     * re-verified by the caller's scoped reader before this point; RBAC/tenant
     * are off (Portaliq is the trusted scoper).
     *
     * @param string $register The register slug/id.
     * @param string $schema   The schema slug/id.
     * @param string $id       The owned object's UUID.
     *
     * @return object|null The ObjectEntity, or null on any resolution failure.
     */
    private function resolveObjectEntity(string $register, string $schema, string $id): ?object
    {
        try {
            $objectService = $this->container->get(self::OBJECT_SERVICE);
            if (is_object($objectService) === false) {
                return null;
            }

            $entity = $objectService->find(
                id: $id,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
            if (is_object($entity) === true) {
                return $entity;
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: object resolve for file attach failed', ['reason' => $e->getMessage()]);
            return null;
        }//end try
    }//end resolveObjectEntity()
}//end class
