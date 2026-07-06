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
 * the referenced target values, and returns only per-row-verified members of
 * that set. Every v2 path fails closed to zero rows (absent claim, malformed
 * scopeClaim, invalid or nested via) — never to a wider read.
 *
 * Contract v2.2 (ADR-046 A5 extension, reverse-scope-join change): a `via` may
 * carry an optional `match` discriminator selecting how the verified target
 * set is applied to the outer rows. `match: 'id'` (the DEFAULT when absent —
 * the byte-for-byte forward behaviour) keeps outer rows whose OWN id/uuid is
 * in the set (the join row references the outer object by id — zaakafhandelapp
 * rol→zaak). `match: 'scopeField'` (reverse) keeps outer rows whose value at
 * the collection's own `scopeField` (dot-path) is in the set — scalar equality
 * or strict array-contains — so an outer row carrying a FOREIGN scope key
 * (scholiq grade-entry.learnerRef) is matched against subject-resolved key
 * VALUES. The join pre-pass, row cap, and tenant discipline are identical in
 * both modes; an empty verified set still yields zero rows, and any `match`
 * value other than the two literals fails the via closed.
 *
 * Field projection (field-projection change): a collection may declare
 * `fields: [...]` — a pure whitelist of top-level row properties. Projection
 * runs AFTER per-row verification and BEFORE returning, on every read path;
 * it shapes what a verified row SHOWS, never which rows return. The row
 * identifier(s) (`id`/`uuid`, flat or as a reduced `@self`) are never
 * stripped, so detail links keep working. Absent `fields` = full row
 * (backward compatible); a malformed declaration projects to identifiers-only
 * (fail-closed narrow, never open).
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
     * @param mixed  $fields          Optional projection whitelist (array of property names); null = full rows.
     *
     * @return array<int, array<string, mixed>> The subject's rows (possibly empty).
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T05
     * @spec openspec/changes/contract-v2/tasks.md#T5
     * @spec openspec/changes/contract-v2/tasks.md#T6
     * @spec openspec/changes/field-projection/tasks.md#T1
     * @spec openspec/changes/reverse-scope-join/tasks.md#T1
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
        string $audience='',
        mixed $fields=null
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
        // The collection's own scopeField rides along so a reverse
        // (`match: 'scopeField'`) join can match the outer rows on it (v2.2).
        if ($via !== null) {
            $joined = $this->readViaCollection(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                scopeField: $scopeField,
                via: $via,
                scopeValue: $scopeValue,
                organisation: $organisation,
                limit: $limit
            );
            return $this->projectRows(rows: $joined, fields: $fields);
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

        $verified = $this->verifyScope(rows: $rows, scopeField: $scopeField, subjectRef: $scopeValue, organisation: $organisation);

        // Field projection runs AFTER per-row verification, BEFORE returning —
        // it shapes what a verified row shows, never which rows return.
        return $this->projectRows(rows: $verified, fields: $fields);
    }//end readCollection()

    /**
     * Project every verified row down to the declared fields whitelist.
     *
     * `$fields === null` means "no projection declared" — rows pass through
     * whole (backward compatible). Anything else is handed to projectRow()
     * per row, including malformed declarations (which fail closed there).
     *
     * @param array<int, array<string, mixed>> $rows   The verified rows.
     * @param mixed                            $fields The raw `fields` declaration.
     *
     * @return array<int, array<string, mixed>> The projected rows.
     *
     * @spec openspec/changes/field-projection/tasks.md#T1
     */
    public function projectRows(array $rows, mixed $fields): array
    {
        if ($fields === null) {
            return $rows;
        }

        $projected = [];
        foreach ($rows as $row) {
            $projected[] = $this->projectRow(row: $row, fields: $fields);
        }

        return $projected;
    }//end projectRows()

    /**
     * Project a single verified row down to the declared fields whitelist.
     *
     * Public on purpose: this is THE single-row projection primitive, so any
     * future single-object/detail read applies identical semantics by calling
     * it before returning. Semantics (field-projection change):
     *
     * - `$fields === null` → the row passes through whole (no declaration).
     * - Pure whitelist: only declared property names that exist as TOP-LEVEL
     *   row keys are kept; unknown declared names are simply absent (a stale
     *   manifest never becomes an error). No dot-path interpretation.
     * - The row identifier(s) are NEVER stripped: flat `id`/`uuid` survive
     *   when present, and an `@self` envelope reduces to only its `id`/`uuid`
     *   members (declare `"@self"` explicitly to keep the full envelope) —
     *   detail links keep working while envelope metadata stays suppressed.
     * - A malformed declaration (non-array, or non-string entries) projects
     *   to identifiers-only: a declared projection intent fails closed
     *   NARROW, never open to the full row (ADR-005) — failing open would
     *   leak exactly the staff-only fields the contributor tried to hide.
     * - `scopeField` values are not auto-included; declare them if wanted.
     *
     * @param array<string, mixed> $row    The verified, normalised row.
     * @param mixed                $fields The raw `fields` declaration.
     *
     * @return array<string, mixed> The projected row.
     *
     * @spec openspec/changes/field-projection/tasks.md#T1
     */
    public function projectRow(array $row, mixed $fields): array
    {
        if ($fields === null) {
            return $row;
        }

        $projected = [];
        foreach ($this->fieldWhitelist(fields: $fields) as $field) {
            if (array_key_exists($field, $row) === true) {
                $projected[$field] = $row[$field];
            }
        }

        return $this->preserveIdentifiers(row: $row, projected: $projected);
    }//end projectRow()

    /**
     * Re-attach the row identifier(s) a projection must never strip (detail
     * links depend on them): flat `id`/`uuid` pass through, and an `@self`
     * envelope — unless explicitly declared — reduces to its `id`/`uuid`
     * members only, so envelope metadata stays suppressed.
     *
     * @param array<string, mixed> $row       The original verified row.
     * @param array<string, mixed> $projected The whitelisted projection so far.
     *
     * @return array<string, mixed> The projection with identifiers preserved.
     *
     * @spec openspec/changes/field-projection/tasks.md#T1
     */
    private function preserveIdentifiers(array $row, array $projected): array
    {
        foreach (['id', 'uuid'] as $idKey) {
            if (array_key_exists($idKey, $row) === true && array_key_exists($idKey, $projected) === false) {
                $projected[$idKey] = $row[$idKey];
            }
        }

        if (array_key_exists('@self', $projected) === true || is_array(($row['@self'] ?? null)) === false) {
            return $projected;
        }

        $self = [];
        foreach (['id', 'uuid'] as $idKey) {
            if (array_key_exists($idKey, $row['@self']) === true) {
                $self[$idKey] = $row['@self'][$idKey];
            }
        }

        if (count($self) > 0) {
            $projected['@self'] = $self;
        }

        return $projected;
    }//end preserveIdentifiers()

    /**
     * Normalise a raw `fields` declaration to a list of usable property
     * names: only non-empty strings survive. A malformed declaration (not an
     * array at all) yields the empty whitelist — identifiers-only downstream.
     *
     * @param mixed $fields The raw `fields` declaration.
     *
     * @return array<int, string>
     */
    private function fieldWhitelist(mixed $fields): array
    {
        $whitelist = [];
        if (is_array($fields) === true) {
            foreach ($fields as $field) {
                if (is_string($field) === true && $field !== '') {
                    $whitelist[] = $field;
                }
            }
        }

        if (count($whitelist) === 0) {
            $this->logger->debug('Portaliq: fields declaration yielded an empty whitelist — projecting to identifiers only');
        }

        return $whitelist;
    }//end fieldWhitelist()

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
     * Read a collection through its one-hop `via` join (contract v2, A5;
     * reverse `match` mode added in v2.2).
     *
     * Join pre-pass first: rows in via.register/via.schema whose (dot-path)
     * via.scopeField equals the scoping value — the query-side filter is
     * best-effort, the PER-ROW dot-path verification is the security boundary.
     * Then the outer read applies the verified target set to the rows the way
     * the `via.match` discriminator selects: `id` (default) keeps rows whose
     * OWN id/uuid is in the set (forward); `scopeField` keeps rows whose value
     * at the collection's own `$scopeField` is in the set (reverse). Both
     * modes re-check membership AND tenant per row. Structurally invalid or
     * nested `via` declarations — and any `match` value other than the two
     * literals — fail closed to zero rows with a logged warning; exactly one
     * hop is contract law (deeper chains must materialise a direct
     * subject-ref property).
     *
     * @param object $objectService OpenRegister's ObjectService.
     * @param string $register      The target register.
     * @param string $schema        The target schema.
     * @param string $scopeField    The collection's own scope field (reverse match).
     * @param mixed  $via           The raw via declaration (validated here).
     * @param string $scopeValue    The subject's scoping value.
     * @param string $organisation  The subject's tenant (may be empty).
     * @param int    $limit         Maximum target rows to return.
     *
     * @return array<int, array<string, mixed>> The verified target rows.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T6
     * @spec openspec/changes/reverse-scope-join/tasks.md#T1
     */
    private function readViaCollection(
        object $objectService,
        string $register,
        string $schema,
        string $scopeField,
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
            // This is also the reverse-mode "never widen" floor: an empty
            // verified set can only ever produce zero rows, never all rows.
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

        // The via is validated, so `match` is absent, 'id', or 'scopeField'.
        $match = (string) ($via['match'] ?? 'id');

        return $this->filterTargetRows(rows: $rows, targets: $targets, organisation: $organisation, match: $match, scopeField: $scopeField);
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
     * Keep only outer rows the verified join set selects — membership checked
     * per row per the `match` mode — plus the shared tenant discipline. The
     * tenant check is applied in BOTH modes (defense in depth on the outer
     * rows, identical to direct reads).
     *
     * @param array<int, mixed>   $rows         The raw outer rows.
     * @param array<string, true> $targets      The verified target set.
     * @param string              $organisation The subject's tenant (may be empty).
     * @param string              $match        'id' (forward) or 'scopeField' (reverse).
     * @param string              $scopeField   The outer collection's scope field (reverse).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/contract-v2/tasks.md#T6
     * @spec openspec/changes/reverse-scope-join/tasks.md#T1
     */
    private function filterTargetRows(array $rows, array $targets, string $organisation, string $match='id', string $scopeField=''): array
    {
        $verified = [];
        foreach ($rows as $row) {
            $normalised = $this->normalise(row: $row);
            if ($normalised === null) {
                continue;
            }

            // Membership in the verified join set — checked per row, per mode.
            if ($this->rowInTargetSet(row: $normalised, targets: $targets, match: $match, scopeField: $scopeField) === false) {
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
     * Whether one outer row is a member of the verified target set under the
     * declared `match` mode. Both branches use strict set membership over the
     * SAME `array<string, true>` set the join pre-pass built, so nothing loose
     * can slip in and an empty set can only ever exclude.
     *
     * - `scopeField` (reverse, v2.2): the outer row carries a foreign scope
     *   key. It is a member when the value at its `$scopeField` (dot-path) is
     *   in the set — scalar equality, OR strict array-contains for a
     *   multi-value field (ANY element in the set matches). An absent/null
     *   value normalises to no candidates → excluded, never a wildcard.
     * - `id` (forward, DEFAULT): the outer row's OWN id/uuid is in the set —
     *   the original A5 behaviour, byte-for-byte.
     *
     * @param array<string, mixed> $row        The normalised outer row.
     * @param array<string, true>  $targets    The verified target set.
     * @param string               $match      'id' or 'scopeField'.
     * @param string               $scopeField The outer collection's scope field.
     *
     * @return bool
     *
     * @spec openspec/changes/reverse-scope-join/tasks.md#T1
     */
    private function rowInTargetSet(array $row, array $targets, string $match, string $scopeField): bool
    {
        if ($match === 'scopeField') {
            // Reuse the same string/array-of-strings normalisation the target
            // set itself was built from (targetRefs), so the two sides compare
            // like-for-like under strict membership.
            foreach ($this->targetRefs(value: $this->dotGet(row: $row, path: $scopeField)) as $key) {
                if (isset($targets[$key]) === true) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->rowIds(row: $row) as $id) {
            if (isset($targets[$id]) === true) {
                return true;
            }
        }

        return false;
    }//end rowInTargetSet()

    /**
     * Structural validation of a `via` declaration: an array carrying
     * non-empty string register/schema/scopeField/targetField members and NO
     * nested `via` (one hop maximum — contract law, ADR-046 A5). The optional
     * `match` discriminator (v2.2) is validated too: absent is fine (defaults
     * to forward `id`), but when present it MUST be exactly `'id'` or
     * `'scopeField'` — any other value fails the via closed (→ zero rows).
     *
     * @param mixed $via The raw declaration.
     *
     * @return bool
     *
     * @spec openspec/changes/reverse-scope-join/tasks.md#T1
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

        if (array_key_exists('match', $via) === true
            && in_array($via['match'], ['id', 'scopeField'], true) === false
        ) {
            return false;
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
