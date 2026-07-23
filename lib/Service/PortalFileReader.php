<?php

/**
 * Portaliq Portal File Reader
 *
 * Resolves and streams a file attached to a contribution object through
 * OpenRegister's existing object-file store, on behalf of an authenticated
 * portal subject (portal-document-download — the read-side counterpart of
 * `PortalFileWriter`). OpenRegister already owns per-object file storage
 * (`FileService::getFile()` / `getFiles()` / `streamFile()`); this reader
 * reuses those services in-process so a download comes from the SAME object
 * folder an upload lands in — no new storage path, no duplicated file
 * plumbing, and no re-implementation of OpenRegister's RFC 6266
 * Content-Disposition sanitisation (`FileService::streamFile()` already
 * builds it — duplicating that logic here would risk drifting out of sync
 * with a security-sensitive header builder).
 *
 * SECURITY: like the object reader/writer and the file writer, this bypasses
 * OpenRegister's NC-user RBAC (portal subjects are not NC users) because
 * Portaliq is the trusted intermediary. The per-subject OWNERSHIP check is the
 * caller's responsibility and MUST have passed BEFORE either method here runs
 * (the controller re-reads the object through the scoped reader and only
 * calls this when the subject owns it) — this class never sees a subject it
 * should refuse, it only resolves/streams once ownership is proven. A file
 * that does not exist inside the OWNED object's own folder resolves to null
 * (`FileService::getFile()` looks up strictly within that folder), so a
 * foreign `fileId` can never resolve here — no existence oracle.
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
 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and streams files attached to an object the subject already owns.
 *
 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
 */
class PortalFileReader
{
    /**
     * OpenRegister's file service.
     */
    private const FILE_SERVICE = 'OCA\\OpenRegister\\Service\\FileService';

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
     * List the files attached to an object the subject already owns, as safe
     * metadata only (id/name/size — the raw stored path is never included).
     *
     * The caller MUST have re-verified ownership through the scoped reader
     * before calling this. Degrades to an empty list on any OR error or when
     * OpenRegister is unavailable — a listing failure never surfaces as an
     * error to the client, it simply shows no files.
     *
     * @param string $id The owned object's id (ownership already verified).
     *
     * @return array<int, array<string, mixed>> The attached files' safe metadata.
     *
     * @spec openspec/specs/supplier-portal/spec.md#identical-404-discipline-no-existence-oracle
     */
    public function listFiles(string $id): array
    {
        $fileService = $this->fileService();
        if ($fileService === null) {
            return [];
        }

        try {
            $files = $fileService->getFiles(object: $id);
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR file list failed', ['reason' => $e->getMessage()]);
            return [];
        }

        if (is_array($files) === false) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            $out[] = $this->normalise(file: $file);
        }

        return $out;
    }//end listFiles()

    /**
     * Stream a file attached to an object the subject already owns.
     *
     * The caller MUST have re-verified ownership through the scoped reader
     * before calling this (a foreign or non-existent object id must never
     * reach here). The file is resolved STRICTLY within the owned object's own
     * folder (`FileService::getFile()`), so a `fileId` belonging to a
     * different object's folder — or no file at all — resolves to null, never
     * a stream. Fails closed to null on any OR error.
     *
     * @param string $id     The owned object's id (ownership already verified).
     * @param string $fileId The requested file's id (never trusted as a
     *                       capability; scoped to the owned object's folder).
     *
     * @return StreamResponse|null The stream response, or null when the file
     *                             cannot be resolved (identical to "does not
     *                             exist" — no existence oracle).
     *
     * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
     */
    public function streamFile(string $id, string $fileId): ?StreamResponse
    {
        $fileService = $this->fileService();
        if ($fileService === null) {
            return null;
        }

        try {
            $file = $fileService->getFile(object: $id, file: $fileId);
            if ($file instanceof File === false) {
                return null;
            }

            return $fileService->streamFile($file);
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR file resolve failed', ['reason' => $e->getMessage()]);
            return null;
        }
    }//end streamFile()

    /**
     * Shape an OpenRegister file Node down to safe metadata for the client —
     * the raw stored path is never included.
     *
     * @param mixed $file The OpenRegister file Node (or array).
     *
     * @return array<string, mixed>
     */
    private function normalise(mixed $file): array
    {
        $name = null;
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
}//end class
