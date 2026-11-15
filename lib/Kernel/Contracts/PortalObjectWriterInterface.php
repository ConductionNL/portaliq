<?php

/**
 * Contract of PortalObjectWriter
 *
 * The public surface of PortalObjectWriter that other components call, so they depend
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
 * Contract of PortalObjectWriter.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalObjectWriterInterface {

	/**
	 * Count objects in a register/schema matching a set of property filters
	 * (portal-session-hardening-v2 — the count-only metrics exposure). Uses
	 * OpenRegister's native `count()` rather than fetching rows, so the audit
	 * trail's contents never travel through this path — only a number does.
	 * Fails closed to 0 when OpenRegister is unavailable or errors; a metrics
	 * endpoint must never 500 because the audit register is unreachable.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Property filters (e.g. `['verb' => 'login']`).
	 *
	 * @return int The matching count, or 0 on failure.
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-session-hardening-v2/tasks.md#T10
	 */
	public function countObjects(string $register, string $schema, array $filters = []): int;

	/**
	 * Create an object with NO ownership stamp — the anonymous sibling of
	 * `createObject()` (portal-page-provisioning). There is no subject: no
	 * `scopeField`/`subjectRef` and no `organisation` is written, whatever
	 * the caller passes in `$data` (already whitelisted by the controller).
	 * The write still goes through OpenRegister's normal `saveObject()`
	 * (`_rbac`/`_multitenancy` off, same trusted-intermediary convention as
	 * every other portal write), so `ObjectCreatedEvent` still fires and any
	 * `x-openregister-flows` declared on the target schema still run —
	 * unchanged, automatic, no code here touches that path.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $data The client-supplied fields (already whitelisted + defaults applied).
	 *
	 * @return array<string, mixed>|null The created object, or null on failure.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	public function createAnonymousObject(string $register, string $schema, array $data): ?array;

	/**
	 * Create an object owned by the subject.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The field stamped with the subject ref.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $organisation The tenant to stamp (may be empty).
	 * @param array<string, mixed> $data The client-supplied fields (already whitelisted).
	 *
	 * @return array<string, mixed>|null The created object, or null on failure.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T06
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-write-must-keep-a-verified-list-and-stamp-a-list-for-an-array-scope-field
	 */
	public function createObject(
		string $register,
		string $schema,
		string $scopeField,
		string $subjectRef,
		string $organisation,
		array $data,
	): ?array;

	/**
	 * Delete ONE row, only when it is the subject's alone: the same ownership
	 * re-read as updateObject() (scope field and tenant), and a row whose
	 * scope field is a list naming anyone else is refused too, so a delete
	 * can never take a row away from another subject. Without a scope field
	 * nothing is deleted. Every refusal and an unknown id answer the same
	 * false, so the caller can give one 404 with no existence oracle.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The field that must own the row.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $organisation The subject's tenant (may be empty).
	 * @param string $id The client-supplied id (never trusted).
	 *
	 * @return bool True when the row was the subject's and is deleted.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
	 */
	public function deleteObject(
		string $register,
		string $schema,
		string $scopeField,
		string $subjectRef,
		string $organisation,
		string $id,
	): bool;

	/**
	 * Why the last create or update was refused, as the store said it, or ''
	 * (site-action-forms). The text stays on the server: WriteRefusal reads
	 * only a field name and a kind from it.
	 *
	 * @return string The refusal, or ''.
	 *
	 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
	 */
	public function lastFailure(): string;

	/**
	 * The server-side ownership stamps a create writes over the client's data.
	 *
	 * The stamps ALWAYS win over any client-supplied value. An `array` scope
	 * field gets the subject's ref as a one-element list. Shared by a create
	 * here and by a draft saved through OpenRegister's submit service, so a
	 * draft that is sent later carries the same owner a create would.
	 *
	 * @param string               $schema       The schema slug.
	 * @param string               $scopeField   The scope field, or '' for none.
	 * @param string               $subjectRef   The stamp value.
	 * @param string               $organisation The organisation, or ''.
	 * @param array<string, mixed> $data         The data.
	 *
	 * @return array<string, mixed> The stamped data.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function stamp(string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array;

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
	 * whitelisted `$data` onto the existing object; (3) keep the stored
	 * organisation as it is: the tenant is stamped on create only, so an
	 * update never overwrites it and never adds one; (4) re-stamp the scope
	 * field so a patch can never move a row out of the subject's scope even
	 * if the scope field somehow reached the whitelist; (5) save with the id
	 * preserved via `uuid` so OR updates, not creates.
	 * Fails closed to null on OR errors and on any ownership failure.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The field that must own the row.
	 * @param string $subjectRef The server-derived subject reference.
	 * @param string $organisation The subject's tenant, used only in the
	 *                             ownership check (may be empty). It is never
	 *                             written on update.
	 * @param string $id The client-supplied object id (never trusted).
	 * @param array<string, mixed>|\Closure $data The client-supplied fields (already whitelisted), or a closure that returns them from the verified row.
	 *
	 * @return array<string, mixed>|null The updated object, or null on ownership/OR failure.
	 *
	 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T2
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-write-must-keep-a-verified-list-and-stamp-a-list-for-an-array-scope-field
	 */
	public function updateObject(
		string $register,
		string $schema,
		string $scopeField,
		string $subjectRef,
		string $organisation,
		string $id,
		array|\Closure $data,
	): ?array;
}//end interface
