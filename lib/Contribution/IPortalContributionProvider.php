<?php

/**
 * Portaliq Portal Contribution Provider Interface
 *
 * The client-facing extension of the integration registry (ADR-019 / ADR-046).
 * A domain app implements this and registers it under the service alias
 * `OCA\Portaliq\Contribution\IPortalContributionProvider::{appId}` so Portaliq's
 * PortalContributionRegistry can discover it (mirrors OpenRegister's MCP tool
 * provider discovery). The provider declares — per external audience — which
 * OpenRegister collections a subject may see, which actions they may take, and
 * which notification keys they receive. No portal logic lives in the app; no
 * domain logic lives in Portaliq.
 *
 * Contract v2 (ADR-046 amendment 2026-07-06) additions are DUCK-TYPED — the
 * interface stays optional, so a contributing app never hard-depends on it:
 *
 * - `getAudiences(): array` — preferred over getAudience() when present; the
 *   provider is consulted for every audience in the list (open vocabulary).
 * - Collection fields: `minTrust` (`low|substantial|high`, default `low`),
 *   `scopeClaim` (`"claimName"` or `"appId.claimName"` — scope by a
 *   server-managed portalAccount claim instead of the subjectRef), and
 *   `via` (`{register, schema, scopeField, targetField}` — one-hop join
 *   scoping; exactly one hop, deeper chains are rejected fail-closed).
 * - Endpoint actions: `{id, label, endpoint, method?, minTrust?}` — an
 *   instance-local absolute path Portaliq forwards to server-to-server with a
 *   short-lived signed `X-Portal-Subject` assertion (never the client bearer).
 *
 * Every v2 field is optional with a v1-equivalent default, so v1 providers and
 * manifests keep working unchanged.
 *
 * Contribution-manifest-v3 (ADR-063) adds an optional, PRESENTATION-ONLY UI
 * configuration vocabulary, also duck-typed and additive — it NEVER widens data
 * access (the `fields` whitelist, collection scope, and read-side projection stay
 * the sole authorities; a fail-closed server-side normaliser sanitises it):
 *
 * - Collections: `columns` (`[{field, label?, render?}]`, render ∈ text|date|
 *   datetime|badge|currency|boolean|link), `detail` (`{layout: card|timeline,
 *   fields?[]}`), `defaultSort` (`{field, direction: asc|desc}`), `defaultFilters`.
 * - Actions: `fieldConfigs` (per-whitelisted-field `{label?, visible?, required?,
 *   disabled?, size?, placeholder?, help?}` — a config for a non-whitelisted field
 *   is dropped), `optionsProviders` (per-field `{type: static, options[]}` or
 *   `{type: collection, register, schema, labelField, valueField}` — a collection
 *   dropdown is populated through the SUBJECT-SCOPED collection endpoint, so it can
 *   only offer values the subject may already read), `submitLabel`, `successMessage`.
 * - Contributions: `pages` (`[{id, label?, icon?, blocks[]}]`) composing typed
 *   blocks (`collection`/`action`/`detail`/`richText`/`cta`) whose references
 *   resolve within the SAME contribution; absent → one default page per listable
 *   collection is synthesised (v2 rendering preserved).
 *
 * portal-page-provisioning adds one further optional, duck-typed field, on
 * BOTH collections and actions:
 *
 * - `anonymous` (`bool`, default `false`) — when `true`, the entry is
 *   surfaced by `PortalContributionRegistry::aggregateAnonymous()` and
 *   reachable with NO bearer session at all (a `type: create` action) or
 *   readable with no session (a collection). Mutually exclusive with a
 *   non-`low` `minTrust` on the SAME entry — `PortalManifestNormaliser`
 *   drops `anonymous` fail-closed when both are declared, so a malformed
 *   manifest can never widen access. A provider that never sets `anonymous`
 *   is byte-identical to today: every entry stays bearer-required.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/changes/contract-v2/tasks.md#T2
 * @spec openspec/changes/portal-page-provisioning/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Contract a domain app implements to contribute to a subject's portal.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
interface IPortalContributionProvider
{
    /**
     * The external audience this provider contributes to.
     *
     * @return string "supplier" or "client".
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    public function getAudience(): string;

    /**
     * Describe this app's contribution for a specific authenticated subject, or
     * null when the app has nothing to contribute to that subject.
     *
     * The returned array is a declarative manifest — never raw domain data:
     * `app`, `label`, `collections` (each with register/schema/label), `actions`
     * (each with id/label/endpoint), and `notifications` (rule keys). Portaliq
     * renders it and reads the collections through OpenRegister, RBAC-scoped to
     * the subject; the app is never called to *list* data (ADR-022).
     *
     * @param array<string, mixed> $subject The resolved subject (subjectRef,
     *                                      audience, organisation, ...), all
     *                                      server-derived.
     *
     * @return array<string, mixed>|null The contribution manifest, or null.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    public function getContribution(array $subject): ?array;
}//end interface
