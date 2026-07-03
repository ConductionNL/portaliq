<?php

/**
 * Portaliq Portal Object Reader
 *
 * Reads the objects in a contribution collection through OpenRegister, scoped to
 * the authenticated subject. Portaliq reads OR directly (ADR-022) rather than
 * calling the domain app to list data. Two guards apply: the query filters on
 * the collection's declared scope field (= the subject reference), and every
 * returned row is re-checked so a mis-scoped OR result can never leak another
 * subject's object (defense in depth, mirroring procest's SupplierScopeService).
 * Degrades to an empty list when OpenRegister is unavailable or errors.
 *
 * Verified against OpenRegister 0.2.17: `ObjectService::findAll(array $config)`
 * takes register/schema/filters inside `$config['filters']` (the older
 * `findAll(register:, schema:, ...)` named-argument form is gone).
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
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Subject-scoped reader over OpenRegister for portal collections.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 */
class PortalObjectReader
{
    /**
     * OpenRegister's object service, resolved lazily.
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
     * Read a collection's objects scoped to the subject.
     *
     * @param string $register     The OpenRegister register slug/id.
     * @param string $schema       The schema slug.
     * @param string $scopeField   The row field that must equal the subject ref.
     * @param string $subjectRef   The server-derived subject reference.
     * @param string $organisation The tenant to constrain to (may be empty).
     * @param int    $limit        Maximum rows to return.
     *
     * @return array<int, array<string, mixed>> The subject's rows (possibly empty).
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T05
     */
    public function readCollection(
        string $register,
        string $schema,
        string $scopeField,
        string $subjectRef,
        string $organisation='',
        int $limit=200
    ): array {
        $objectService = $this->objectService();
        if ($objectService === null) {
            return [];
        }

        $filters = [];
        if ($scopeField !== '' && $subjectRef !== '') {
            $filters[$scopeField] = $subjectRef;
        }

        // NOTE: `organisation` is deliberately NOT an OR filter — it is a
        // multitenancy field, not a queryable property, so filtering on it
        // returns nothing. Tenant isolation is the per-row organisation check in
        // verifyScope(); broader tenant scoping for anonymous portal reads is a
        // follow-up (see T05 notes).
        try {
            // Set the register/schema context via the dedicated setters, exactly
            // as OpenRegister's own controllers do. Passing register/schema inside
            // `filters` would leak them as literal property filters (no object has
            // those properties), matching nothing.
            $objectService->setRegister(register: $register);
            $objectService->setSchema(schema: $schema);
            // RBAC + multitenancy OFF: portal subjects are NOT Nextcloud users, so
            // OR's user/org-based scoping would filter everything out. Portaliq is
            // the trusted intermediary — it authenticated the subject via the
            // bearer and scopes by the subjectRef filter + per-row verification
            // below, which IS the security boundary.
            $rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => $limit, 'offset' => 0], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return [];
        }

        if (is_array($rows) === false) {
            return [];
        }

        return $this->verifyScope(rows: $rows, scopeField: $scopeField, subjectRef: $subjectRef, organisation: $organisation);
    }//end readCollection()

    /**
     * Re-check every row against the subject ref (and organisation, when known).
     * Any row that does not carry the exact subject ref — or belongs to a
     * different tenant — is dropped, so a mis-scoped OR result never leaks.
     *
     * @param array<int, mixed> $rows         The raw rows from OpenRegister.
     * @param string            $scopeField   The scope field to check.
     * @param string            $subjectRef   The expected subject reference.
     * @param string            $organisation The expected tenant (empty = skip).
     *
     * @return array<int, array<string, mixed>> The verified rows.
     */
    private function verifyScope(array $rows, string $scopeField, string $subjectRef, string $organisation=''): array
    {
        $verified = [];
        foreach ($rows as $row) {
            $normalised = $this->normalise(row: $row);
            if ($normalised === null) {
                continue;
            }

            if ($scopeField !== '' && (string) ($normalised[$scopeField] ?? '') !== $subjectRef) {
                continue;
            }

            // Only enforce tenant isolation when the row actually carries an
            // organisation; schemas without one (e.g. procest supplierTender) are
            // scoped by the subject reference alone, which is globally unique.
            $rowOrganisation = (string) ($normalised['organisation'] ?? '');
            if ($organisation !== '' && $rowOrganisation !== '' && $rowOrganisation !== $organisation) {
                continue;
            }

            $verified[] = $normalised;
        }

        return $verified;
    }//end verifyScope()

    /**
     * Normalise an OpenRegister row (array or object) to an associative array.
     *
     * @param mixed $row The row.
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
