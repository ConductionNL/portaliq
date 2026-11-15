<?php

/**
 * Contract of PortalObjectReader
 *
 * The public surface of PortalObjectReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of PortalObjectReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalObjectReaderInterface {

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
	): array;

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
	): ?array;

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
	public function readObjectAsUser(string $register, string $schema, string $id): ?array;

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
	): array;

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
	public function resolveScopeValue(string $scopeClaim, string $contributingApp, array $subject): ?string;
}//end interface
