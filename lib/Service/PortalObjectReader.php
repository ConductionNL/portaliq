<?php

/**
 * Portaliq Portal Object Reader
 *
 * Reads the objects in a contribution collection through OpenRegister, scoped to
 * the authenticated subject. Portaliq reads OR directly (ADR-022) rather than
 * calling the domain app to list data. Two guards apply: the query filters on
 * the collection's declared scope field, and every returned row is re-checked so
 * a mis-scoped OR result can never leak another subject's object (defense in
 * depth, mirroring procest's SupplierScopeService). Degrades to an empty list
 * when OpenRegister is unavailable or errors.
 *
 * Contract v2 (ADR-046 amendment): the scoping VALUE is the subject's
 * pseudonymous subjectRef by default, or — when the collection declares
 * `scopeClaim` — a server-managed claim resolved from the subject's own
 * portalAccount (`claims[appId][claimName]`; never client input). A collection
 * may additionally declare a one-hop `via` join: the reader first resolves the
 * join rows whose (dot-path) scope field matches the scoping value, collects
 * the referenced target ids, and returns only per-row-verified members of that
 * set. Every v2 path fails closed to zero rows (absent claim, malformed
 * scopeClaim, invalid or nested via) — never to a wider read.
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
 * @spec openspec/changes/contract-v2/tasks.md#T5
 * @spec openspec/changes/contract-v2/tasks.md#T6
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the complexity is
 * fail-closed guard clauses by design (ADR-005): every v2 scoping path
 * (claim, via, direct) re-validates structure and per-row ownership before
 * anything is returned; collapsing the guards would trade auditability for a
 * score.
 */
class PortalObjectReader
{
    /**
     * OpenRegister's object service, resolved lazily.
     */
    private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

    /**
     * Row cap for the `via` join pre-pass (contract v2, A5) — bounds join
     * amplification while comfortably covering portal-scale link tables.
     */
    private const JOIN_ROW_CAP = 500;

    /**
     * Grammar for scopeClaim segments (`appId` and `claimName` alike).
     */
    private const CLAIM_SEGMENT = '/^[a-z][a-zA-Z0-9_]*$/';

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
     * The scoping VALUE defaults to the subject's own subjectRef; a declared
     * `scopeClaim` replaces it with a server-managed claim resolved from the
     * subject's portalAccount (contract v2, A4). An absent or malformed claim
     * yields zero rows WITHOUT any collection query (fail-closed empty). A
     * declared `via` join routes through the one-hop pre-pass instead of the
     * direct read (contract v2, A5).
     *
     * @param string $register        The OpenRegister register slug/id.
     * @param string $schema          The schema slug.
     * @param string $scopeField      The row field that must equal the scope value.
     * @param string $subjectRef      The server-derived subject reference.
     * @param string $organisation    The tenant to constrain to (may be empty).
     * @param int    $limit           Maximum rows to return.
     * @param string $scopeClaim      Optional claim address (`claimName` or `appId.claimName`).
     * @param string $contributingApp The app the contribution came from (bare-claim namespace).
     * @param mixed  $via             Optional one-hop join declaration (array), fail-closed on anything else.
     * @param string $audience        The subject's audience (portalAccount lookup filter).
     *
     * @return array<int, array<string, mixed>> The subject's rows (possibly empty).
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T05
     * @spec openspec/changes/contract-v2/tasks.md#T5
     * @spec openspec/changes/contract-v2/tasks.md#T6
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters map
     * 1:1 onto the declarative contract-v2 collection fields; folding them
     * into an options array would lose the type safety on a security boundary.
     */
    public function readCollection(
        string $register,
        string $schema,
        string $scopeField,
        string $subjectRef,
        string $organisation='',
        int $limit=200,
        string $scopeClaim='',
        string $contributingApp='',
        mixed $via=null,
        string $audience=''
    ): array {
        $objectService = $this->objectService();
        if ($objectService === null) {
            return [];
        }

        // Contract v2 (A4): a declared scopeClaim replaces the scoping value
        // with a server-resolved claim from the subject's OWN portalAccount.
        $scopeValue = $subjectRef;
        if ($scopeClaim !== '') {
            $claimValue = $this->resolveClaim(
                objectService: $objectService,
                scopeClaim: $scopeClaim,
                contributingApp: $contributingApp,
                subjectRef: $subjectRef,
                audience: $audience,
                organisation: $organisation
            );
            if ($claimValue === null) {
                // Absent/malformed claim → the collection contributes nothing.
                // A normal (unlinked-account) state — 200 + empty, never an
                // error, and never an unscoped OR query.
                return [];
            }

            $scopeValue = $claimValue;
        }

        // Contract v2 (A5): a declared via joins one hop before the target read.
        if ($via !== null) {
            return $this->readViaCollection(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                via: $via,
                scopeValue: $scopeValue,
                organisation: $organisation,
                limit: $limit
            );
        }

        $filters = [];
        if ($scopeField !== '' && $scopeValue !== '') {
            $filters[$scopeField] = $scopeValue;
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
            // bearer and scopes by the scope-value filter + per-row verification
            // below, which IS the security boundary.
            $rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => $limit, 'offset' => 0], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return [];
        }

        if (is_array($rows) === false) {
            return [];
        }

        return $this->verifyScope(rows: $rows, scopeField: $scopeField, subjectRef: $scopeValue, organisation: $organisation);
    }//end readCollection()

    /**
     * Resolve a scopeClaim to its value from the subject's OWN portalAccount.
     *
     * Addressing (contract v2, A4): `"appId.claimName"` explicit, or a bare
     * `"claimName"` resolved in the CONTRIBUTING app's namespace. The first
     * `.` splits; both segments must match `[a-z][a-zA-Z0-9_]*` — anything
     * malformed is treated as absent (fail-closed). The account row itself is
     * per-row verified exactly like every portal read, and claims are only
     * ever read here, server-side — never from client input.
     *
     * @param object $objectService   OpenRegister's ObjectService.
     * @param string $scopeClaim      The declared claim address.
     * @param string $contributingApp Namespace for the bare form.
     * @param string $subjectRef      The subject's own reference.
     * @param string $audience        The subject's audience.
     * @param string $organisation    The subject's tenant (may be empty).
     *
     * @return string|null The claim value, or null when absent/malformed.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T5
     */
    private function resolveClaim(
        object $objectService,
        string $scopeClaim,
        string $contributingApp,
        string $subjectRef,
        string $audience,
        string $organisation
    ): ?string {
        $address = $this->parseClaimAddress(scopeClaim: $scopeClaim, contributingApp: $contributingApp);
        if ($address === null) {
            $this->logger->debug('Portaliq: malformed scopeClaim treated as absent', ['scopeClaim' => $scopeClaim]);
            return null;
        }

        if ($subjectRef === '') {
            return null;
        }

        $account = $this->fetchOwnAccount(
            objectService: $objectService,
            subjectRef: $subjectRef,
            audience: $audience,
            organisation: $organisation
        );
        if ($account === null) {
            return null;
        }

        $claims = ($account['claims'] ?? null);
        $value  = null;
        if (is_array($claims) === true && is_array(($claims[$address['appId']] ?? null)) === true) {
            $value = ($claims[$address['appId']][$address['claimName']] ?? null);
        }

        if (is_string($value) === false || $value === '') {
            return null;
        }

        return $value;
    }//end resolveClaim()

    /**
     * Parse a scopeClaim address into its appId + claimName, or null when the
     * grammar is violated (fail-closed). The FIRST `.` splits appId from
     * claimName; the bare form resolves in the contributing app's namespace.
     *
     * @param string $scopeClaim      The declared claim address.
     * @param string $contributingApp Namespace for the bare form.
     *
     * @return array{appId: string, claimName: string}|null
     */
    private function parseClaimAddress(string $scopeClaim, string $contributingApp): ?array
    {
        $appId     = $contributingApp;
        $claimName = $scopeClaim;

        $dot = strpos($scopeClaim, '.');
        if ($dot !== false) {
            $appId     = substr($scopeClaim, 0, $dot);
            $claimName = substr($scopeClaim, ($dot + 1));
        }

        if (preg_match(self::CLAIM_SEGMENT, $appId) !== 1 || preg_match(self::CLAIM_SEGMENT, $claimName) !== 1) {
            return null;
        }

        return [
            'appId'     => $appId,
            'claimName' => $claimName,
        ];
    }//end parseClaimAddress()

    /**
     * Load the subject's OWN portalAccount row, per-row verified exactly like
     * every portal read (subjectRef match + tenant discipline), or null.
     *
     * @param object $objectService OpenRegister's ObjectService.
     * @param string $subjectRef    The subject's own reference.
     * @param string $audience      The subject's audience.
     * @param string $organisation  The subject's tenant (may be empty).
     *
     * @return array<string, mixed>|null
     */
    private function fetchOwnAccount(object $objectService, string $subjectRef, string $audience, string $organisation): ?array
    {
        try {
            $objectService->setRegister(register: 'portaliq');
            $objectService->setSchema(schema: 'portalAccount');
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'subjectRef' => $subjectRef,
                        'audience'   => $audience,
                    ],
                    'limit'   => 2,
                    'offset'  => 0,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: portalAccount lookup failed', ['reason' => $e->getMessage()]);
            return null;
        }

        if (is_array($rows) === false) {
            return null;
        }

        $accounts = $this->verifyScope(rows: $rows, scopeField: 'subjectRef', subjectRef: $subjectRef, organisation: $organisation);
        if (count($accounts) === 0) {
            return null;
        }

        return $accounts[0];
    }//end fetchOwnAccount()

    /**
     * Read a collection through its one-hop `via` join (contract v2, A5).
     *
     * Join pre-pass first: rows in via.register/via.schema whose (dot-path)
     * via.scopeField equals the scoping value — the query-side filter is
     * best-effort, the PER-ROW dot-path verification is the security boundary.
     * Then the target read keeps only rows whose id/uuid is in the verified
     * targetField set. Structurally invalid or nested `via` declarations fail
     * closed to zero rows with a logged warning; exactly one hop is contract
     * law (deeper chains must materialise a direct subject-ref property).
     *
     * @param object $objectService OpenRegister's ObjectService.
     * @param string $register      The target register.
     * @param string $schema        The target schema.
     * @param mixed  $via           The raw via declaration (validated here).
     * @param string $scopeValue    The subject's scoping value.
     * @param string $organisation  The subject's tenant (may be empty).
     * @param int    $limit         Maximum target rows to return.
     *
     * @return array<int, array<string, mixed>> The verified target rows.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T6
     */
    private function readViaCollection(
        object $objectService,
        string $register,
        string $schema,
        mixed $via,
        string $scopeValue,
        string $organisation,
        int $limit
    ): array {
        if ($this->isValidVia(via: $via) === false) {
            $this->logger->warning('Portaliq: invalid via declaration — failing closed to zero rows', ['register' => $register, 'schema' => $schema]);
            return [];
        }

        if ($scopeValue === '') {
            return [];
        }

        $targets = $this->verifiedJoinTargets(objectService: $objectService, via: $via, scopeValue: $scopeValue, organisation: $organisation);
        if (count($targets) === 0) {
            // A subject without join rows is a normal state — empty, no error.
            return [];
        }

        try {
            $objectService->setRegister(register: $register);
            $objectService->setSchema(schema: $schema);
            $rows = $objectService->findAll(config: ['filters' => [], 'limit' => $limit, 'offset' => 0], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: OR read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
            return [];
        }

        if (is_array($rows) === false) {
            return [];
        }

        return $this->filterTargetRows(rows: $rows, targets: $targets, organisation: $organisation);
    }//end readViaCollection()

    /**
     * Run the join pre-pass and collect the verified target references: query
     * the join schema (query-side filter best-effort, row-capped), verify each
     * row's dot-path scope value + tenant PER ROW — the actual security
     * boundary — and union the targetField references of the survivors.
     *
     * @param object               $objectService OpenRegister's ObjectService.
     * @param array<string, mixed> $via           The validated via declaration.
     * @param string               $scopeValue    The subject's scoping value.
     * @param string               $organisation  The subject's tenant (may be empty).
     *
     * @return array<string, true> Verified target ids as a lookup set.
     */
    private function verifiedJoinTargets(object $objectService, array $via, string $scopeValue, string $organisation): array
    {
        $joinScopeField  = (string) $via['scopeField'];
        $joinTargetField = (string) $via['targetField'];

        try {
            $objectService->setRegister(register: (string) $via['register']);
            $objectService->setSchema(schema: (string) $via['schema']);
            // Query-side filter is best-effort (dot paths may not filter
            // server-side); the per-row verification below is the boundary.
            $joinRows = $objectService->findAll(
                config: [
                    'filters' => [$joinScopeField => $scopeValue],
                    'limit'   => self::JOIN_ROW_CAP,
                    'offset'  => 0,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning('Portaliq: via join read failed', ['schema' => (string) $via['schema'], 'reason' => $e->getMessage()]);
            return [];
        }

        if (is_array($joinRows) === false) {
            return [];
        }

        $targets = [];
        foreach ($joinRows as $joinRow) {
            $row = $this->normalise(row: $joinRow);
            if ($row === null) {
                continue;
            }

            if ($this->joinRowMatches(row: $row, path: $joinScopeField, scopeValue: $scopeValue) === false) {
                continue;
            }

            if ($this->organisationMatches(row: $row, organisation: $organisation) === false) {
                continue;
            }

            foreach ($this->targetRefs(value: $this->dotGet(row: $row, path: $joinTargetField)) as $ref) {
                $targets[$ref] = true;
            }
        }//end foreach

        return $targets;
    }//end verifiedJoinTargets()

    /**
     * Per-row dot-path verification of a join row against the scoping value
     * (scalar equality, or strict containment for multi-value properties).
     *
     * @param array<string, mixed> $row        The normalised join row.
     * @param string               $path       The dot path to the scope property.
     * @param string               $scopeValue The subject's scoping value.
     *
     * @return bool
     */
    private function joinRowMatches(array $row, string $path, string $scopeValue): bool
    {
        $value = $this->dotGet(row: $row, path: $path);
        if ($value === $scopeValue) {
            return true;
        }

        if (is_array($value) === true) {
            return in_array($scopeValue, $value, true);
        }

        return false;
    }//end joinRowMatches()

    /**
     * Keep only target rows whose id/uuid is in the verified join set —
     * membership checked per row — plus the shared tenant discipline.
     *
     * @param array<int, mixed>   $rows         The raw target rows.
     * @param array<string, true> $targets      The verified target-id set.
     * @param string              $organisation The subject's tenant (may be empty).
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterTargetRows(array $rows, array $targets, string $organisation): array
    {
        $verified = [];
        foreach ($rows as $row) {
            $normalised = $this->normalise(row: $row);
            if ($normalised === null) {
                continue;
            }

            // Membership in the verified join set — checked per row.
            $member = false;
            foreach ($this->rowIds(row: $normalised) as $id) {
                if (isset($targets[$id]) === true) {
                    $member = true;
                    break;
                }
            }

            if ($member === false) {
                continue;
            }

            if ($this->organisationMatches(row: $normalised, organisation: $organisation) === false) {
                continue;
            }

            $verified[] = $normalised;
        }//end foreach

        return $verified;
    }//end filterTargetRows()

    /**
     * Structural validation of a `via` declaration: an array carrying
     * non-empty string register/schema/scopeField/targetField members and NO
     * nested `via` (one hop maximum — contract law, ADR-046 A5).
     *
     * @param mixed $via The raw declaration.
     *
     * @return bool
     */
    private function isValidVia(mixed $via): bool
    {
        if (is_array($via) === false) {
            return false;
        }

        if (array_key_exists('via', $via) === true) {
            return false;
        }

        foreach (['register', 'schema', 'scopeField', 'targetField'] as $member) {
            if (is_string(($via[$member] ?? null)) === false || $via[$member] === '') {
                return false;
            }
        }

        return true;
    }//end isValidVia()

    /**
     * Normalise a targetField value to a list of non-empty string references
     * (string or array of strings accepted — OR relations convention).
     *
     * @param mixed $value The raw targetField value.
     *
     * @return array<int, string>
     */
    private function targetRefs(mixed $value): array
    {
        if (is_string($value) === true && $value !== '') {
            return [$value];
        }

        $refs = [];
        if (is_array($value) === true) {
            foreach ($value as $ref) {
                if (is_string($ref) === true && $ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }//end targetRefs()

    /**
     * Traverse a row by a dot path (e.g. `betrokkeneIdentificatie.inpBsn`).
     *
     * @param array<string, mixed> $row  The normalised row.
     * @param string               $path The dot-separated path.
     *
     * @return mixed The value at the path, or null when any segment is missing.
     */
    private function dotGet(array $row, string $path): mixed
    {
        $value = $row;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) === false || array_key_exists($segment, $value) === false) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }//end dotGet()

    /**
     * Collect a row's identifier candidates (`id` / `uuid`, flat or in the
     * `@self` envelope) for join-set membership checks.
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
     * The per-row tenant check shared by every portal read path: enforced only
     * when both the subject and the row carry an organisation.
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
            if ($this->organisationMatches(row: $normalised, organisation: $organisation) === false) {
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
