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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCA\Portaliq\Event\PortalClaimScopeEvent;
use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Service\Tenancy\ActsForTheOrganisation;
use OCA\Portaliq\Service\Tenancy\ClaimScopeCheck;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     -- the length is the
 * per-path scoping helpers (claim, forward/reverse via, projection) plus the
 * single-object read reusing them; each carries its own security rationale, so
 * splitting the file would scatter one security boundary across classes.
 * @SuppressWarnings(PHPMD.TooManyMethods)          -- the same boundary: the
 * public read of an `anonymous` collection shares the tenant check, the row
 * normalising and the projection with the scoped reads, so it lives beside them.
 */
class PortalObjectReader implements ScopedCollectionReaderInterface, PortalObjectReaderInterface {
	use ActsForTheOrganisation;
	use PortalScopeMatch;

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
	 * The stateless row and reference helpers, built on first use.
	 *
	 * @var PortalRowRefs|null
	 */
	private ?PortalRowRefs $rowRefs = null;

	/**
	 * Grammar for scopeClaim segments (`appId` and `claimName` alike).
	 */
	private const CLAIM_SEGMENT = '/^[a-z][a-zA-Z0-9_]*$/';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalFieldProjector $projector Shapes a verified row down to the
	 *                                        declared `fields` whitelist. Runs
	 *                                        AFTER verification, so it never
	 *                                        decides which rows return.
	 * @param PortalFilteredRows $filteredRows The via outer query and the declared-filter check.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalFieldProjector $projector,
		private readonly PortalFilteredRows $filteredRows=new PortalFilteredRows(new \Psr\Log\NullLogger()),
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
	 * @param string $register The OpenRegister register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The row field that must equal the scope value.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $organisation The tenant to constrain to (may be empty).
	 * @param int $limit Maximum rows to return.
	 * @param string $scopeClaim Optional claim address (`claimName` or `appId.claimName`).
	 * @param string $contributingApp The app the contribution came from (bare-claim namespace).
	 * @param mixed $via Optional one-hop join declaration (array), fail-closed on anything else.
	 * @param string $audience The subject's audience (portalAccount lookup filter).
	 * @param mixed $fields Optional projection whitelist (array of property names); null = full rows.
	 * @param array $filter Optional declared narrowing filter, e.g. a supertype discriminator
	 *                      (`['ticketType' => 'request']`). Narrows only — the scope filter is
	 *                      applied after it and always wins, so it can never widen access.
	 *
	 * @return array<int, array<string, mixed>> The subject's rows (possibly empty).
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T05
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
	 * @spec openspec/changes/archive/2026-09-07-field-projection/tasks.md#T1
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
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
		string $organisation = '',
		int $limit = 200,
		string $scopeClaim = '',
		string $contributingApp = '',
		mixed $via = null,
		string $audience = '',
		mixed $fields = null,
		array $filter = [],
	): array {
		$objectService = $this->actingObjectService(organisation: $organisation);
		if ($objectService === null) {
			return [];
		}

		// Contract v2 (A4): a declared scopeClaim replaces the scoping value
		// with a server-resolved claim from the subject's OWN portalAccount.
		// Absent/malformed claim → the collection contributes nothing: a
		// normal (unlinked-account) state, never an unscoped OR query.
		$scopeValue = $this->scopeValueFor(
			objectService: $objectService,
			subjectRef: $subjectRef,
			scopeClaim: $scopeClaim,
			contributingApp: $contributingApp,
			audience: $audience,
			organisation: $organisation
		);
		if ($scopeValue === null) {
			return [];
		}

		$verified = $this->scopedRows(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			scopeField: $scopeField,
			via: $via,
			scopeValue: $scopeValue,
			organisation: $organisation,
			limit: $limit,
			filter: $filter
		);

		// Field projection runs AFTER per-row verification, BEFORE returning —
		// it shapes what a verified row shows, never which rows return.
		return $this->projector->projectRows(rows: $verified, fields: $fields);
	}//end readCollection()

	/**
	 * The verified rows of a collection read, through its declared via join
	 * or directly on its scope field.
	 *
	 * Contract v2 (A5): a declared via joins one hop before the target read.
	 * The collection's own scopeField rides along so a reverse
	 * (`match: 'scopeField'`) join can match the outer rows on it (v2.2).
	 *
	 * @param object $objectService The acting OR object service.
	 * @param string $register The OpenRegister register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The row field carrying the scope value.
	 * @param mixed $via Optional one-hop join declaration.
	 * @param string $scopeValue The resolved scoping value.
	 * @param string $organisation The tenant to constrain to (may be empty).
	 * @param int $limit Maximum rows to return.
	 * @param array $filter The declared narrowing filter.
	 *
	 * @return array<int, array<string, mixed>> The verified rows (possibly empty).
	 *
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- same contract fields as readCollection.
	 */
	private function scopedRows(
		object $objectService,
		string $register,
		string $schema,
		string $scopeField,
		mixed $via,
		string $scopeValue,
		string $organisation,
		int $limit,
		array $filter,
	): array {
		if ($via !== null) {
			return $this->readViaCollection(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				scopeField: $scopeField,
				via: $via,
				scopeValue: $scopeValue,
				organisation: $organisation,
				limit: $limit,
				filter: $filter
			);
		}

		return $this->readDirectCollection(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			scopeField: $scopeField,
			scopeValue: $scopeValue,
			organisation: $organisation,
			limit: $limit,
			filter: $filter
		);
	}//end scopedRows()

	/**
	 * The scoping value for a read: the subject reference, or — when the
	 * collection declares a scopeClaim — the server-resolved claim from the
	 * subject's OWN portalAccount. Null when the declared claim is absent or
	 * malformed: the caller then answers empty / not found, never an
	 * unscoped read.
	 *
	 * @param object $objectService The acting OR object service.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $scopeClaim Optional claim address.
	 * @param string $contributingApp The app the contribution came from.
	 * @param string $audience The subject's audience.
	 * @param string $organisation The tenant to constrain to.
	 *
	 * @return string|null The scoping value, or null when the claim fails closed.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function scopeValueFor(
		object $objectService,
		string $subjectRef,
		string $scopeClaim,
		string $contributingApp,
		string $audience,
		string $organisation,
	): ?string {
		if ($scopeClaim === '') {
			return $subjectRef;
		}

		return $this->resolveClaim(
			objectService: $objectService,
			scopeClaim: $scopeClaim,
			contributingApp: $contributingApp,
			subjectRef: $subjectRef,
			audience: $audience,
			organisation: $organisation
		);
	}//end scopeValueFor()

	/**
	 * A direct (non-via) scoped collection read: the scope-filtered OR query,
	 * then the per-row verification every portal read uses.
	 *
	 * @param object $objectService The acting OR object service.
	 * @param string $register The OpenRegister register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The row field carrying the scope value.
	 * @param string $scopeValue The resolved scoping value.
	 * @param string $organisation The tenant to constrain to (may be empty).
	 * @param int $limit Maximum rows to return.
	 * @param array $filter The declared narrowing filter.
	 *
	 * @return array<int, array<string, mixed>> The verified rows (possibly empty).
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T05
	 */
	private function readDirectCollection(
		object $objectService,
		string $register,
		string $schema,
		string $scopeField,
		string $scopeValue,
		string $organisation,
		int $limit,
		array $filter,
	): array {
		$filters = $this->rowRefs()->scopedFilters(filter: $filter, scopeField: $scopeField, scopeValue: $scopeValue);

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

		return $this->verifyScope(rows: $rows, scopeField: $scopeField, subjectRef: $scopeValue, organisation: $organisation, schema: $schema);
	}//end readDirectCollection()

	/**
	 * The rows the subject holds in any of several scope fields, once each:
	 * the rows they own and the rows they are assigned to or take part in.
	 * Each field is one scoped read with its own per-row verification, so a
	 * row returns only when the subject is in the field it was read by.
	 *
	 * @param string $register The OpenRegister register slug/id.
	 * @param string $schema The schema slug.
	 * @param array<int, string> $scopeFields The row fields, each a single value or a list.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $organisation The tenant to constrain to (may be empty).
	 * @param int $limit Maximum rows per field.
	 *
	 * @return array<int, array<string, mixed>> The rows, in the order first read.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t02
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function readCollectionByAnyScope(
		string $register,
		string $schema,
		array $scopeFields,
		string $subjectRef,
		string $organisation = '',
		int $limit = 200,
	): array {
		if ($subjectRef === '') {
			return [];
		}

		$rows = [];
		foreach (array_unique($scopeFields) as $scopeField) {
			if (is_string($scopeField) === false || $scopeField === '') {
				continue;
			}

			$read = $this->readCollection(
				register: $register,
				schema: $schema,
				scopeField: $scopeField,
				subjectRef: $subjectRef,
				organisation: $organisation,
				limit: $limit
			);
			$rows = $this->withUnseenRows(rows: $rows, read: $read);
		}

		return array_values($rows);
	}//end readCollectionByAnyScope()

	/**
	 * The rows so far, keyed by id, plus every read row not seen yet.
	 *
	 * @param array<string, array<string, mixed>> $rows The rows so far, keyed by id.
	 * @param array<int, array<string, mixed>> $read The rows of one scoped read.
	 *
	 * @return array<string, array<string, mixed>> The rows, keyed by id.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t02
	 */
	private function withUnseenRows(array $rows, array $read): array {
		foreach ($read as $row) {
			$key = (string)($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? ''));
			if ($key !== '' && isset($rows[$key]) === false) {
				$rows[$key] = $row;
			}
		}

		return $rows;
	}//end withUnseenRows()

	/**
	 * The rows of a collection its app declares `anonymous`: what the public
	 * may read of the schema, not what the subject owns. OpenRegister's own
	 * read rules decide, with RBAC ON for the caller as it is (a resident has
	 * no Nextcloud account, so the schema's `public` rule applies), so this
	 * read can never show more than OpenRegister would show that caller.
	 * The declared `filter` narrows, a row of another tenant is dropped, and
	 * the field whitelist shapes every row.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $organisation The tenant of the session (may be empty).
	 * @param int $limit The most rows to read.
	 * @param mixed $fields Optional projection whitelist; null = full row.
	 * @param array<string, mixed> $filter The collection's declared narrowing filter.
	 *
	 * @return array<int, array<string, mixed>> The rows, or [] when OpenRegister is absent or the read failed.
	 *
	 * @spec openspec/changes/anonymous-collection-reads-what-the-public-may/specs/portal-contribution-contract/spec.md#requirement-an-anonymous-collection-shows-what-the-public-may-read
	 */
	public function readPublicCollection(
		string $register,
		string $schema,
		string $organisation = '',
		int $limit = 200,
		mixed $fields = null,
		array $filter = [],
	): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
			$filters = $this->rowRefs()->scopedFilters(filter: $filter, scopeField: '', scopeValue: '');
			$rows    = $objectService->findAll(config: ['filters' => $filters, 'limit' => $limit, 'offset' => 0], _rbac: true, _multitenancy: true);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR public read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$verified = $this->tenantRows(rows: $rows, organisation: $organisation, schema: $schema);

		return $this->projector->projectRows(rows: $verified, fields: $fields);
	}//end readPublicCollection()

	/**
	 * The normalised rows that belong to the tenant.
	 *
	 * @param array<int, mixed> $rows The raw rows from OpenRegister.
	 * @param string $organisation The tenant of the session (may be empty).
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The tenant's rows.
	 *
	 * @spec openspec/changes/anonymous-collection-reads-what-the-public-may/specs/portal-contribution-contract/spec.md#requirement-an-anonymous-collection-shows-what-the-public-may-read
	 */
	private function tenantRows(array $rows, string $organisation, string $schema): array {
		$verified = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised !== null && $this->rowRefs()->organisationMatches(row: $normalised, organisation: $organisation, schema: $schema) === true) {
				$verified[] = $normalised;
			}
		}

		return $verified;
	}//end tenantRows()

	/**
	 * One page of a scoped read, or null when OpenRegister is absent or the
	 * read failed, so a caller that must finish a job (the admin's revoke-all)
	 * can tell "nothing there" from "could not look". The declared filter
	 * narrows, the scope filter always wins, and every row is verified on its
	 * scope value and organisation like readCollection().
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The row field that must equal the scope value.
	 * @param string $scopeValue The scope value.
	 * @param string $organisation The tenant to constrain to (may be empty).
	 * @param array<string, mixed> $filter A narrowing filter.
	 * @param int $limit Rows per page.
	 * @param int $offset Rows to skip.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, read: int}|null The page's
	 *         verified rows and how many rows OpenRegister returned before
	 *         verification (the paging count), or null.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function readScopedPage(
		string $register,
		string $schema,
		string $scopeField,
		string $scopeValue,
		string $organisation,
		array $filter,
		int $limit,
		int $offset,
	): ?array {
		$objectService = $this->actingObjectService(organisation: $organisation);
		if ($objectService === null) {
			return null;
		}

		$filters = $this->rowRefs()->scopedFilters(filter: $filter, scopeField: $scopeField, scopeValue: $scopeValue);
		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(config: ['filters' => $filters, 'limit' => $limit, 'offset' => $offset], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR page read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		return [
			'rows' => $this->verifyScope(rows: $rows, scopeField: $scopeField, subjectRef: $scopeValue, organisation: $organisation, schema: $schema),
			'read' => count($rows),
		];
	}//end readScopedPage()

	/**
	 * Read a SINGLE object by id, scoped to the subject exactly like
	 * readCollection scopes a list (portal-scoped-crud, ADR-062 Phase 1).
	 *
	 * The scoping value is resolved identically (scopeClaim → resolveClaim,
	 * else the subjectRef); the object is fetched by id and then re-checked
	 * through the SAME per-row ownership boundary — verifyScope for a direct
	 * collection, or the one-hop `via` membership machinery for a via
	 * collection. An object whose scope value is not the subject's (or that
	 * fails the tenant check, or does not exist, or an absent/malformed claim)
	 * returns null so the controller answers 404 — indistinguishable from
	 * "not found", giving NO existence oracle. Field projection runs last,
	 * exactly as on the list path. Fail-closed on everything (missing OR, OR
	 * error, malformed row) → null.
	 *
	 * @param string $register The OpenRegister register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The row field that must equal the scope value.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $id The client-supplied object id/uuid (never trusted).
	 * @param string $organisation The tenant to constrain to (may be empty).
	 * @param string $scopeClaim Optional claim address (`claimName` or `appId.claimName`).
	 * @param string $contributingApp The app the contribution came from (bare-claim namespace).
	 * @param mixed $via Optional one-hop join declaration (array), fail-closed on anything else.
	 * @param string $audience The subject's audience (portalAccount lookup filter).
	 * @param mixed $fields Optional projection whitelist (array of property names); null = full row.
	 * @param array<string, mixed> $filter The collection's declared filter; a row outside it is null.
	 *
	 * @return array<string, mixed>|null The subject's object, or null (→ 404).
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters map
	 * 1:1 onto the declarative collection fields, identical to readCollection;
	 * folding them into an options array would lose type safety on a security
	 * boundary.
	 */
	public function readObject(
		string $register,
		string $schema,
		string $scopeField,
		string $subjectRef,
		string $id,
		string $organisation = '',
		string $scopeClaim = '',
		string $contributingApp = '',
		mixed $via = null,
		string $audience = '',
		mixed $fields = null,
		array $filter = [],
	): ?array {
		$objectService = $this->actingServiceForId(id: $id, organisation: $organisation);
		if ($objectService === null) {
			return null;
		}

		// Resolve the scoping value the SAME way readCollection does: an
		// absent/malformed claim fails closed to null (→ 404), never an
		// unscoped fetch.
		$scopeValue = $this->scopeValueFor(
			objectService: $objectService,
			subjectRef: $subjectRef,
			scopeClaim: $scopeClaim,
			contributingApp: $contributingApp,
			audience: $audience,
			organisation: $organisation
		);
		if ($scopeValue === null) {
			return null;
		}

		$row = $this->fetchById(objectService: $objectService, register: $register, schema: $schema, id: $id);
		$row = $this->withinFilter(row: $row, filter: $filter, scopeField: $scopeField);
		if ($row === null) {
			return null;
		}

		$verified = $this->verifiedObject(
			objectService: $objectService,
			scopeField: $scopeField,
			via: $via,
			scopeValue: $scopeValue,
			organisation: $organisation,
			schema: $schema,
			row: $row
		);

		return $this->projectedRow(row: $verified, fields: $fields);
	}//end readObject()

	/**
	 * The verified row shaped down to the field whitelist, or null when nothing was verified.
	 *
	 * @param array<string, mixed>|null $row The verified row, or null.
	 * @param mixed $fields Optional projection whitelist; null = full row.
	 *
	 * @return array<string, mixed>|null The projected row, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function projectedRow(array|null $row, mixed $fields): array|null {
		if ($row === null) {
			return null;
		}

		return $this->projector->projectRow(row: $row, fields: $fields);
	}//end projectedRow()

	/**
	 * The acting object service for a read by id, or null for an empty id or
	 * when OpenRegister is absent.
	 *
	 * @param string $id The client-supplied object id/uuid.
	 * @param string $organisation The tenant to constrain to (may be empty).
	 *
	 * @return object|null The acting OR object service, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function actingServiceForId(string $id, string $organisation): object|null {
		if ($id === '') {
			return null;
		}

		return $this->actingObjectService(organisation: $organisation);
	}//end actingServiceForId()

	/**
	 * The fetched row when the subject may see it, else null (→ 404).
	 *
	 * A via collection: the object must ALSO pass the one-hop via membership
	 * (same join pre-pass + tenant + match discipline as the list path),
	 * reusing the exact machinery readViaCollection uses. Direct scope: the
	 * row runs through the SAME per-row ownership check every portal read
	 * uses, so a foreign-owned row is dropped.
	 *
	 * @param object $objectService The acting OR object service.
	 * @param string $scopeField The row field carrying the scope value.
	 * @param mixed $via Optional one-hop join declaration.
	 * @param string $scopeValue The resolved scoping value.
	 * @param string $organisation The tenant to constrain to.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $row The fetched row.
	 *
	 * @return array<string, mixed>|null The verified row, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function verifiedObject(
		object $objectService,
		string $scopeField,
		mixed $via,
		string $scopeValue,
		string $organisation,
		string $schema,
		array $row,
	): ?array {
		if ($via !== null) {
			return $this->verifyViaObject(
				objectService: $objectService,
				scopeField: $scopeField,
				via: $via,
				scopeValue: $scopeValue,
				organisation: $organisation,
				row: $row
			);
		}

		$verified = $this->verifyScope(rows: [$row], scopeField: $scopeField, subjectRef: $scopeValue, organisation: $organisation, schema: $schema);
		if (count($verified) === 0) {
			return null;
		}

		return $verified[0];
	}//end verifiedObject()

	/**
	 * The row when it satisfies the collection's declared filter, else null:
	 * a row the list would never show is not shown by id either.
	 *
	 * @param array<string, mixed>|null $row The fetched row.
	 * @param array<string, mixed> $filter The declared filter.
	 * @param string $scopeField The collection's scope field.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-07-via-read-scoped-query/tasks.md#T2
	 */
	private function withinFilter(?array $row, array $filter, string $scopeField): ?array {
		if ($row === null || $this->filteredRows->matchesFilter(row: $row, filter: $filter, scopeField: $scopeField) === false) {
			return null;
		}

		return $row;
	}//end withinFilter()

	/**
	 * Read one row as the signed-in Nextcloud user, with OpenRegister's own
	 * authorization ON: the schema's `authorization.read` rule is judged
	 * against the user's groups. Every other read here runs for a portal
	 * subject, who is no Nextcloud user, and so runs with RBAC off. A staff
	 * surface whose caller IS a Nextcloud user reads through this instead
	 * (portaliq#799), so a schema an organisation narrowed stays narrowed on
	 * portaliq's own routes too.
	 *
	 * No subject scope and no projection apply: the caller gates who may read
	 * the row before or after, as its own permission check. A refusal, an
	 * OpenRegister error and a missing row are the same null.
	 *
	 * @param string $register The OpenRegister register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $id The object id/uuid.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md
	 */
	public function readObjectAsUser(string $register, string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		return $this->fetchById(objectService: $objectService, register: $register, schema: $schema, id: $id, rbac: true);
	}//end readObjectAsUser()

	/**
	 * Fetch a single OpenRegister row by id/uuid (best-effort query-side
	 * filter — the per-row ownership check in readObject is the boundary). The
	 * id is matched in-memory against the row's identifier candidates so it
	 * works whether OR filtered server-side or returned a wider set.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $id The client-supplied id/uuid.
	 * @param bool $rbac Whether OpenRegister judges the read against the
	 *                   signed-in user's rights (readObjectAsUser only).
	 *
	 * @return array<string, mixed>|null The normalised row, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- handed to OpenRegister's
	 * own `_rbac` flag unchanged; readObject() and readObjectAsUser() are the
	 * named entry points.
	 */
	private function fetchById(object $objectService, string $register, string $schema, string $id, bool $rbac = false): ?array {
		// OR only honours DATA-property filters, so findAll(filters:['id'=>…])
		// does NOT select by identifier — fetch by id directly with find().
		// Tenant off. RBAC off for a portal subject, whose ownership the caller
		// re-verifies via verifyScope; on for a staff read (readObjectAsUser).
		try {
			$entity = $objectService->find(
				id: $id,
				register: $register,
				schema: $schema,
				_rbac: $rbac,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR single read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->rowWithId(entity: $entity, id: $id);
	}//end fetchById()

	/**
	 * The normalised row of a fetched entity when it carries the requested id, else null.
	 *
	 * @param mixed $entity The entity OpenRegister returned (may be null).
	 * @param string $id The client-supplied id/uuid.
	 *
	 * @return array<string, mixed>|null The normalised row, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function rowWithId(mixed $entity, string $id): array|null {
		if ($entity === null) {
			return null;
		}

		$row = $this->normalise(row: $entity);
		if ($row === null) {
			return null;
		}

		// Defence: confirm the resolved row actually carries the requested id.
		if (in_array($id, $this->rowRefs()->rowIds(row: $row), true) === false) {
			return null;
		}

		return $row;
	}//end rowWithId()

	/**
	 * Verify a single fetched row against a one-hop `via` join, reusing the
	 * exact list-path machinery (validate → verified join pre-pass → per-row
	 * membership + tenant under the declared `match` mode). Fails closed to
	 * null on an invalid/nested/malformed-`match` via, an empty scoping value,
	 * an empty verified target set, or a non-member row.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $scopeField The collection's own scope field (reverse match).
	 * @param mixed $via The raw via declaration (validated here).
	 * @param string $scopeValue The subject's scoping value.
	 * @param string $organisation The subject's tenant (may be empty).
	 * @param array<string, mixed> $row The normalised fetched row.
	 *
	 * @return array<string, mixed>|null The verified row, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function verifyViaObject(
		object $objectService,
		string $scopeField,
		mixed $via,
		string $scopeValue,
		string $organisation,
		array $row,
	): ?array {
		if ($this->viaObjectReadable(via: $via, row: $row, scopeValue: $scopeValue) === false) {
			return null;
		}

		$targets = $this->verifiedJoinTargets(objectService: $objectService, via: $via, scopeValue: $scopeValue, organisation: $organisation);
		if (count($targets) === 0) {
			return null;
		}

		$match = $this->matchMode(via: $via);
		$verified = $this->filterTargetRows(rows: [$row], targets: $targets, organisation: $organisation, match: $match, scopeField: $scopeField);

		return $this->firstRow(rows: $verified);
	}//end verifyViaObject()

	/**
	 * The first of the verified rows, or null when there is none.
	 *
	 * @param array<int, array<string, mixed>> $rows The verified rows.
	 *
	 * @return array<string, mixed>|null The first row, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function firstRow(array $rows): array|null {
		if (count($rows) === 0) {
			return null;
		}

		return $rows[0];
	}//end firstRow()

	/**
	 * Whether a single read may go through the via join: a valid via
	 * declaration (an invalid one is logged) and a non-empty scoping value.
	 *
	 * @param mixed $via The raw via declaration.
	 * @param array<string, mixed> $row The normalised fetched row.
	 * @param string $scopeValue The subject's scoping value.
	 *
	 * @return bool True when the join may run.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T1
	 */
	private function viaObjectReadable(mixed $via, array $row, string $scopeValue): bool {
		if (is_array($via) === false || $this->rowRefs()->isValidVia(via: $via) === false) {
			$this->logger->warning(
				'Portaliq: invalid via declaration on single read — failing closed',
				['schema' => (string)($row['@self']['schema'] ?? '')]
			);
			return false;
		}

		return $scopeValue !== '';
	}//end viaObjectReadable()

	/**
	 * The `match` mode of a validated via declaration: absent, 'id', or 'scopeField'.
	 *
	 * @param array<string, mixed> $via The validated via declaration.
	 *
	 * @return string The match mode, 'id' when absent.
	 *
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
	 */
	private function matchMode(array $via): string {
		return (string)($via['match'] ?? 'id');
	}//end matchMode()

	/**
	 * Resolve the OWNERSHIP scope value for a subject on a collection/action —
	 * the value the writer/reader compares against `row[scopeField]`. Without a
	 * `scopeClaim` this is the subject's own `subjectRef`; WITH one it is the
	 * server-resolved claim from the subject's portalAccount (contract v2, A4).
	 * Shared by the write path so a status transition on a claim-scoped
	 * collection re-verifies ownership by the SAME resolved value as the read —
	 * a claim resolving to null (absent/malformed) fails closed to null so no
	 * write can happen.
	 *
	 * @param string $scopeClaim The declared claim address ('' = none).
	 * @param string $contributingApp Namespace for the bare form.
	 * @param array<string, mixed> $subject The resolved subject (subjectRef/audience/organisation).
	 *
	 * @return string|null The scope value, or null when a declared claim is absent.
	 *
	 * @spec openspec/changes/archive/2026-09-29-portal-status-transitions/tasks.md#T2
	 */
	public function resolveScopeValue(string $scopeClaim, string $contributingApp, array $subject): ?string {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($scopeClaim === '') {
			return $subjectRef;
		}

		$objectService = $this->actingObjectService(organisation: (string)($subject['organisation'] ?? ''));
		if ($objectService === null) {
			return null;
		}

		return $this->resolveClaim(
			objectService: $objectService,
			scopeClaim: $scopeClaim,
			contributingApp: $contributingApp,
			subjectRef: $subjectRef,
			audience: (string)($subject['audience'] ?? ''),
			organisation: (string)($subject['organisation'] ?? '')
		);
	}//end resolveScopeValue()

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
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $scopeClaim The declared claim address.
	 * @param string $contributingApp Namespace for the bare form.
	 * @param string $subjectRef The subject's own reference.
	 * @param string $audience The subject's audience.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return string|null The claim value, or null when absent/malformed.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function resolveClaim(
		object $objectService,
		string $scopeClaim,
		string $contributingApp,
		string $subjectRef,
		string $audience,
		string $organisation,
	): ?string {
		$address = $this->claimAddressFor(scopeClaim: $scopeClaim, contributingApp: $contributingApp, subjectRef: $subjectRef);
		if ($address === null) {
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

		return $this->admittedAccountClaim(account: $account, address: $address, organisation: $organisation);
	}//end resolveClaim()

	/**
	 * The account's claim value when it is a non-empty string the owning app admits, else null.
	 *
	 * @param array<string, mixed> $account The subject's own portalAccount row.
	 * @param array{appId: string, claimName: string} $address The claim address.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return string|null The claim value, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function admittedAccountClaim(array $account, array $address, string $organisation): string|null {
		$value = $this->accountClaim(account: $account, address: $address);
		if ($value === null) {
			return null;
		}

		if ($this->claimAdmitted(address: $address, value: $value, account: $account, organisation: $organisation) === false) {
			return null;
		}

		return $value;
	}//end admittedAccountClaim()

	/**
	 * The parsed claim address, or null when it is malformed (logged) or the
	 * subject reference is empty.
	 *
	 * @param string $scopeClaim The declared claim address.
	 * @param string $contributingApp Namespace for the bare form.
	 * @param string $subjectRef The subject's own reference.
	 *
	 * @return array{appId: string, claimName: string}|null
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function claimAddressFor(string $scopeClaim, string $contributingApp, string $subjectRef): array|null {
		$address = $this->parseClaimAddress(scopeClaim: $scopeClaim, contributingApp: $contributingApp);
		if ($address === null) {
			$this->logger->debug('Portaliq: malformed scopeClaim treated as absent', ['scopeClaim' => $scopeClaim]);
			return null;
		}

		if ($subjectRef === '') {
			return null;
		}

		return $address;
	}//end claimAddressFor()

	/**
	 * The claim's value on the account, or null unless it is a non-empty string.
	 *
	 * @param array<string, mixed> $account The subject's own portalAccount row.
	 * @param array{appId: string, claimName: string} $address The claim address.
	 *
	 * @return string|null The claim value, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function accountClaim(array $account, array $address): string|null {
		$value = $this->claimIn(claims: ($account['claims'] ?? null), address: $address);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end accountClaim()

	/**
	 * The raw value at the claim address in an account's claims, or null.
	 *
	 * @param mixed $claims The account's claims.
	 * @param array{appId: string, claimName: string} $address The claim address.
	 *
	 * @return mixed The raw claim value, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function claimIn(mixed $claims, array $address): mixed {
		$value = null;
		if (is_array($claims) === true && is_array(($claims[$address['appId']] ?? null)) === true) {
			$value = ($claims[$address['appId']][$address['claimName']] ?? null);
		}

		return $value;
	}//end claimIn()

	/**
	 * Whether the owning app admits the claim: it may refuse a claim that
	 * names a record of another tenant.
	 *
	 * @param array{appId: string, claimName: string} $address The claim address.
	 * @param string $value The claim value.
	 * @param array<string, mixed> $account The subject's own portalAccount row.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return bool True when the claim is admitted.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
	 */
	private function claimAdmitted(array $address, string $value, array $account, string $organisation): bool {
		$check = new PortalClaimScopeEvent(
			claim: $address,
			holder: ['value' => $value, 'organisation' => (string)($account['organisation'] ?? $organisation)]
		);

		return (new ClaimScopeCheck(container: $this->container, logger: $this->logger))->admits(event: $check);
	}//end claimAdmitted()

	/**
	 * Parse a scopeClaim address into its appId + claimName, or null when the
	 * grammar is violated (fail-closed). The FIRST `.` splits appId from
	 * claimName; the bare form resolves in the contributing app's namespace.
	 *
	 * @param string $scopeClaim The declared claim address.
	 * @param string $contributingApp Namespace for the bare form.
	 *
	 * @return array{appId: string, claimName: string}|null
	 */
	private function parseClaimAddress(string $scopeClaim, string $contributingApp): ?array {
		$appId = $contributingApp;
		$claimName = $scopeClaim;

		$dot = strpos($scopeClaim, '.');
		if ($dot !== false) {
			$appId = substr($scopeClaim, 0, $dot);
			$claimName = substr($scopeClaim, ($dot + 1));
		}

		if (preg_match(self::CLAIM_SEGMENT, $appId) !== 1 || preg_match(self::CLAIM_SEGMENT, $claimName) !== 1) {
			return null;
		}

		return [
			'appId' => $appId,
			'claimName' => $claimName,
		];
	}//end parseClaimAddress()

	/**
	 * Load the subject's OWN portalAccount row, per-row verified exactly like
	 * every portal read (subjectRef match + tenant discipline), or null.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef The subject's own reference.
	 * @param string $audience The subject's audience.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetchOwnAccount(object $objectService, string $subjectRef, string $audience, string $organisation): ?array {
		try {
			$objectService->setRegister(register: 'portaliq');
			$objectService->setSchema(schema: 'portalAccount');
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'subjectRef' => $subjectRef,
						'audience' => $audience,
					],
					'limit' => 2,
					'offset' => 0,
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

		$accounts = $this->verifyScope(
			rows: $rows,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			schema: 'portalAccount'
		);
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
	 * @param string $register The target register.
	 * @param string $schema The target schema.
	 * @param string $scopeField The collection's own scope field (reverse match).
	 * @param mixed $via The raw via declaration (validated here).
	 * @param string $scopeValue The subject's scoping value.
	 * @param string $organisation The subject's tenant (may be empty).
	 * @param int $limit Maximum target rows to return.
	 * @param array<string, mixed> $filter The collection's declared narrowing filter.
	 *
	 * @return array<int, array<string, mixed>> The verified target rows.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
	 * @spec openspec/changes/archive/2026-10-07-via-read-scoped-query/tasks.md#T1
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters map
	 * 1:1 onto the declarative collection fields, identical to readCollection.
	 */
	private function readViaCollection(
		object $objectService,
		string $register,
		string $schema,
		string $scopeField,
		mixed $via,
		string $scopeValue,
		string $organisation,
		int $limit,
		array $filter=[],
	): array {
		if ($this->viaReadable(via: $via, register: $register, schema: $schema, scopeValue: $scopeValue) === false) {
			return [];
		}

		$targets = $this->verifiedJoinTargets(objectService: $objectService, via: $via, scopeValue: $scopeValue, organisation: $organisation);
		if (count($targets) === 0) {
			// A subject without join rows is a normal state — empty, no error.
			// This is also the reverse-mode "never widen" floor: an empty
			// verified set can only ever produce zero rows, never all rows.
			return [];
		}

		// The via is validated, so `match` is absent, 'id', or 'scopeField'.
		$match = $this->matchMode(via: $via);

		if ($this->contextSet(objectService: $objectService, register: $register, schema: $schema) === false) {
			return [];
		}

		$rows = $this->filteredRows->outerRows(
			objectService: $objectService,
			schema: $schema,
			scopeField: $scopeField,
			targets: $targets,
			match: $match,
			limit: $limit,
			filter: $filter
		);
		if ($rows === null) {
			return [];
		}

		return $this->filterTargetRows(rows: $rows, targets: $targets, organisation: $organisation, match: $match, scopeField: $scopeField);
	}//end readViaCollection()

	/**
	 * Whether a via collection read may run: a valid via declaration (an
	 * invalid one is logged) and a non-empty scoping value.
	 *
	 * @param mixed $via The raw via declaration.
	 * @param string $register The target register.
	 * @param string $schema The target schema.
	 * @param string $scopeValue The subject's scoping value.
	 *
	 * @return bool True when the read may run.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
	 */
	private function viaReadable(mixed $via, string $register, string $schema, string $scopeValue): bool {
		if ($this->rowRefs()->isValidVia(via: $via) === false) {
			$this->logger->warning('Portaliq: invalid via declaration — failing closed to zero rows', ['register' => $register, 'schema' => $schema]);
			return false;
		}

		return $scopeValue !== '';
	}//end viaReadable()

	/**
	 * Set the register/schema context on the object service; false (logged) when that fails.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The target register.
	 * @param string $schema The target schema.
	 *
	 * @return bool True when the context is set.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
	 */
	private function contextSet(object $objectService, string $register, string $schema): bool {
		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end contextSet()

	/**
	 * Run the join pre-pass and collect the verified target references: query
	 * the join schema (query-side filter best-effort, row-capped), verify each
	 * row's dot-path scope value + tenant PER ROW — the actual security
	 * boundary — and union the targetField references of the survivors.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $via The validated via declaration.
	 * @param string $scopeValue The subject's scoping value.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return array<string, true> Verified target ids as a lookup set.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	private function verifiedJoinTargets(object $objectService, array $via, string $scopeValue, string $organisation): array {
		$joinRows = $this->joinRows(objectService: $objectService, via: $via, scopeValue: $scopeValue);
		if ($joinRows === null) {
			return [];
		}

		return $this->joinTargetsOf(joinRows: $joinRows, via: $via, scopeValue: $scopeValue, organisation: $organisation);
	}//end verifiedJoinTargets()

	/**
	 * The raw rows of the join pre-pass (query-side filter best-effort,
	 * row-capped), or null when the read failed (logged) or returned no list.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $via The validated via declaration.
	 * @param string $scopeValue The subject's scoping value.
	 *
	 * @return array<int, mixed>|null The raw join rows, or null.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	private function joinRows(object $objectService, array $via, string $scopeValue): array|null {
		$joinScopeField = (string)$via['scopeField'];

		try {
			$objectService->setRegister(register: (string)$via['register']);
			$objectService->setSchema(schema: (string)$via['schema']);
			// Query-side filter is best-effort (dot paths may not filter
			// server-side); the per-row verification below is the boundary.
			$joinRows = $objectService->findAll(
				config: [
					'filters' => [$joinScopeField => $scopeValue],
					'limit' => self::JOIN_ROW_CAP,
					'offset' => 0,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: via join read failed', ['schema' => (string)$via['schema'], 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($joinRows) === false) {
			return null;
		}

		return $joinRows;
	}//end joinRows()

	/**
	 * Verify each join row's dot-path scope value + tenant + liveness PER ROW
	 * and union the targetField references of the survivors.
	 *
	 * @param array<int, mixed> $joinRows The raw join rows.
	 * @param array<string, mixed> $via The validated via declaration.
	 * @param string $scopeValue The subject's scoping value.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return array<string, true> Verified target ids as a lookup set.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	private function joinTargetsOf(array $joinRows, array $via, string $scopeValue, string $organisation): array {
		$joinTargetField = (string)$via['targetField'];

		$targets = [];
		$liveRows = new ViaJoinRowFilter();
		$now = new DateTimeImmutable();
		foreach ($joinRows as $joinRow) {
			$row = $this->matchingJoinRow(joinRow: $joinRow, via: $via, scopeValue: $scopeValue, organisation: $organisation);

			// A withdrawn, terminated, revoked or expired join row grants
			// nothing (site-mijn-omgeving-components REQ-SMO-023).
			if ($row === null || $liveRows->grants(row: $row, via: $via, now: $now) === false) {
				continue;
			}

			foreach ($this->rowRefs()->targetRefs(value: $this->rowRefs()->dotGet(row: $row, path: $joinTargetField)) as $ref) {
				$targets[$ref] = true;
			}
		}

		return $targets;
	}//end joinTargetsOf()

	/**
	 * The normalised join row when its dot-path scope value and tenant match, else null.
	 *
	 * @param mixed $joinRow The raw join row.
	 * @param array<string, mixed> $via The validated via declaration.
	 * @param string $scopeValue The subject's scoping value.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return array<string, mixed>|null The matching row, or null.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	private function matchingJoinRow(mixed $joinRow, array $via, string $scopeValue, string $organisation): array|null {
		$row = $this->normalise(row: $joinRow);
		if ($row === null) {
			return null;
		}

		if ($this->rowRefs()->joinRowMatches(row: $row, path: (string)$via['scopeField'], scopeValue: $scopeValue) === false) {
			return null;
		}

		if ($this->rowRefs()->organisationMatches(row: $row, organisation: $organisation) === false) {
			return null;
		}

		return $row;
	}//end matchingJoinRow()

	/**
	 * Keep only outer rows the verified join set selects — membership checked
	 * per row per the `match` mode — plus the shared tenant discipline. The
	 * tenant check is applied in BOTH modes (defense in depth on the outer
	 * rows, identical to direct reads).
	 *
	 * @param array<int, mixed> $rows The raw outer rows.
	 * @param array<string, true> $targets The verified target set.
	 * @param string $organisation The subject's tenant (may be empty).
	 * @param string $match 'id' (forward) or 'scopeField' (reverse).
	 * @param string $scopeField The outer collection's scope field (reverse).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T6
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
	 */
	private function filterTargetRows(array $rows, array $targets, string $organisation, string $match = 'id', string $scopeField = ''): array {
		$verified = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			// Membership in the verified join set — checked per row, per mode.
			if ($this->rowRefs()->rowInTargetSet(row: $normalised, targets: $targets, match: $match, scopeField: $scopeField) === false) {
				continue;
			}

			if ($this->rowRefs()->organisationMatches(row: $normalised, organisation: $organisation) === false) {
				continue;
			}

			$verified[] = $normalised;
		}//end foreach

		return $verified;
	}//end filterTargetRows()

	/**
	 * Re-check every row against the subject ref (and organisation, when known).
	 * Any row whose scope field is not the exact subject ref, or a list that
	 * contains it, is dropped, and so is a row from a different tenant, so a
	 * mis-scoped OR result never leaks.
	 *
	 * @param array<int, mixed> $rows The raw rows from OpenRegister.
	 * @param string $scopeField The scope field to check.
	 * @param string $subjectRef The expected subject reference.
	 * @param string $organisation The expected tenant (empty = skip).
	 * @param string $schema The schema read, when known; an organisation-scoped one refuses a missing tenant value.
	 *
	 * @return array<int, array<string, mixed>> The verified rows.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership
	 */
	private function verifyScope(array $rows, string $scopeField, string $subjectRef, string $organisation = '', string $schema = ''): array {
		$verified = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			if ($this->scopeFails(row: $normalised, scopeField: $scopeField, subjectRef: $subjectRef) === true) {
				continue;
			}

			if ($this->tenantHolds(row: $normalised, scopeField: $scopeField, organisation: $organisation, schema: $schema) === false) {
				continue;
			}

			$verified[] = $normalised;
		}

		return $verified;
	}//end verifyScope()

	/**
	 * Whether a row fails the subject scope. One rule for single values and
	 * lists (portal-scope-list-membership): an equal value, or a list that
	 * contains it. Every other shape and an empty scoping value drop the row.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 * @param string $scopeField The scope field to check ('' = no subject scope).
	 * @param string $subjectRef The expected subject reference.
	 *
	 * @return bool True when the row must be dropped.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership
	 */
	private function scopeFails(array $row, string $scopeField, string $subjectRef): bool {
		return $scopeField !== '' && $this->scopeMatches(stored: ($row[$scopeField] ?? null), scopeValue: $subjectRef) === false;
	}//end scopeFails()

	/**
	 * Whether a row passes tenant isolation. With a subject scope it applies
	 * only when the row carries an organisation; schemas without one are
	 * scoped by the subject reference alone, which is globally unique.
	 * Without a subject scope the tenant is the only boundary, and it fails closed.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 * @param string $scopeField The scope field ('' = no subject scope).
	 * @param string $organisation The expected tenant (empty = skip).
	 * @param string $schema The schema read, when known.
	 *
	 * @return bool True when the tenant holds.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership
	 */
	private function tenantHolds(array $row, string $scopeField, string $organisation, string $schema): bool {
		$tenantHolds = $this->rowRefs()->organisationMatches(row: $row, organisation: $organisation, schema: $schema);
		if ($scopeField === '') {
			$tenantHolds = $this->rowRefs()->tenantMatches(row: $row, organisation: $organisation);
		}

		return $tenantHolds;
	}//end tenantHolds()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
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

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
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

	/**
	 * The row and reference helpers.
	 *
	 * @return PortalRowRefs
	 */
	private function rowRefs(): PortalRowRefs {
		if ($this->rowRefs === null) {
			$this->rowRefs = new PortalRowRefs();
		}

		return $this->rowRefs;
	}//end rowRefs()
}//end class
