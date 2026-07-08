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
     * Update an object owned by the subject (portal-scoped-crud, ADR-062
     * Phase 1 — closes the write-IDOR concern, Conduction/portaliq#16).
     *
     * SECURITY INVARIANT: ownership is re-verified against OpenRegister BEFORE
     * any write; the client-supplied id is NEVER trusted; the scope field is
     * re-stamped on the merged data. The flow is: (1) re-read the existing
     * object by id and confirm `row[scopeField] === subjectRef` plus the tenant
     * check — the SAME per-row boundary the reader enforces — returning null
     * (→ 404, no write) if it is not the subject's; (2) merge the already-
     * whitelisted `$data` onto the existing object; (3) re-stamp the scope
     * field (and organisation) so a patch can never move a row out of the
     * subject's scope even if the scope field somehow reached the whitelist;
     * (4) save with the id preserved via `uuid` so OR updates, not creates.
     * Fails closed to null on OR errors and on any ownership failure.
     *
     * @param string               $register     The register slug/id.
     * @param string               $schema       The schema slug.
     * @param string               $scopeField   The field that must own the row.
     * @param string               $subjectRef   The server-derived subject reference.
     * @param string               $organisation The tenant to stamp (may be empty).
     * @param string               $id           The client-supplied object id (never trusted).
     * @param array<string, mixed> $data         The client-supplied fields (already whitelisted).
     *
     * @return array<string, mixed>|null The updated object, or null on ownership/OR failure.
     *
     * @spec openspec/changes/portal-scoped-crud/tasks.md#T2
     */
    public function updateObject(
        string $register,
        string $schema,
        string $scopeField,
        string $subjectRef,
        string $organisation,
        string $id,
        array $data
    ): ?array {
        if ($id === '') {
            return null;
        }

        $objectService = $this->objectService();
        if ($objectService === null) {
            return null;
        }

        // (1) VERIFY OWNERSHIP FIRST — re-read the row by id and confirm it is
        // the subject's. No write happens unless this returns the owned row.
        $existing = $this->fetchOwnedObject(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            scopeField: $scopeField,
            subjectRef: $subjectRef,
            organisation: $organisation,
            id: $id
        );
        if ($existing === null) {
            return null;
        }

        // (2) Merge the whitelisted fields onto the existing object; drop the
        // OR-managed `@self` envelope (id/schema/register travel as explicit
        // save arguments, not as object data).
        $merged = array_merge($existing, $data);
        unset($merged['@self']);

        // (3) RE-STAMP the ownership fields AFTER the merge, so a client value
        // can never win — a patch can never move the row out of scope.
        if ($scopeField !== '') {
            $merged[$scopeField] = $subjectRef;
        }

        if ($organisation !== '') {
            $merged['organisation'] = $organisation;
        }

        // (4) Save with the id preserved (`uuid`) so OR UPDATES this row.
        try {
            $saved = $objectService->saveObject(
                object: $merged,
                register: $register,
                schema: $schema,
                uuid: $id,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR update failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return null;
        }

        return $this->normalise(row: $saved);
    }//end updateObject()

    /**
     * Re-read a row by id and return it ONLY when it is the subject's: the row
     * must carry the exact subject ref at `scopeField` and pass the tenant
     * check — the SAME per-row ownership boundary the reader's verifyScope
     * enforces. The client-supplied id is matched in-memory against the row's
     * identifier candidates (best-effort query-side filter). Any mismatch —
     * foreign owner, wrong tenant, or non-existent id — returns null, so the
     * caller writes nothing and the controller cannot leak an existence oracle.
     *
     * @param object $objectService OpenRegister's ObjectService.
     * @param string $register      The register slug/id.
     * @param string $schema        The schema slug.
     * @param string $scopeField    The field that must own the row.
     * @param string $subjectRef    The subject's reference.
     * @param string $organisation  The subject's tenant (may be empty).
     * @param string $id            The client-supplied id/uuid.
     *
     * @return array<string, mixed>|null The owned row, or null.
     *
     * @spec openspec/changes/portal-scoped-crud/tasks.md#T2
     */
    private function fetchOwnedObject(
        object $objectService,
        string $register,
        string $schema,
        string $scopeField,
        string $subjectRef,
        string $organisation,
        string $id
    ): ?array {
        try {
            $objectService->setRegister(register: $register);
            $objectService->setSchema(schema: $schema);
            $rows = $objectService->findAll(
                config: ['filters' => ['id' => $id], 'limit' => 100, 'offset' => 0],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR ownership read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return null;
        }

        if (is_array($rows) === false) {
            return null;
        }

        foreach ($rows as $raw) {
            $row = $this->normalise(row: $raw);
            if ($row === null) {
                continue;
            }

            if (in_array($id, $this->rowIds(row: $row), true) === false) {
                continue;
            }

            // THE ownership boundary — identical to the reader's verifyScope.
            if ($scopeField !== '' && (string) ($row[$scopeField] ?? '') !== $subjectRef) {
                return null;
            }

            if ($this->organisationMatches(row: $row, organisation: $organisation) === false) {
                return null;
            }

            return $row;
        }//end foreach

        return null;
    }//end fetchOwnedObject()

    /**
     * Collect a row's identifier candidates (`id` / `uuid`, flat or in the
     * `@self` envelope) for the ownership id match.
     *
     * @param array<string, mixed> $row The normalised row.
     *
     * @return array<int, string>
     */
    private function rowIds(array $row): array
    {
        $self       = ($row['@self'] ?? null);
        $candidates = [
            ($row['id'] ?? null),
            ($row['uuid'] ?? null),
        ];
        if (is_array($self) === true) {
            $candidates[] = ($self['id'] ?? null);
            $candidates[] = ($self['uuid'] ?? null);
        }

        $ids = [];
        foreach ($candidates as $candidate) {
            if ((is_string($candidate) === true || is_int($candidate) === true) && (string) $candidate !== '') {
                $ids[] = (string) $candidate;
            }
        }

        return $ids;
    }//end rowIds()

    /**
     * The per-row tenant check shared with the reader: enforced only when both
     * the subject and the row carry an organisation.
     *
     * @param array<string, mixed> $row          The normalised row.
     * @param string               $organisation The expected tenant (empty = skip).
     *
     * @return bool
     */
    private function organisationMatches(array $row, string $organisation): bool
    {
        $rowOrganisation = (string) ($row['organisation'] ?? '');
        return $organisation === '' || $rowOrganisation === '' || $rowOrganisation === $organisation;
    }//end organisationMatches()

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
