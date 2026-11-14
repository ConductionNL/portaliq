<?php

/**
 * Contract of PortalOrganisationConfigService
 *
 * The public surface of PortalOrganisationConfigService that other components call, so they depend
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
 * Contract of PortalOrganisationConfigService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalOrganisationConfigServiceInterface {

	/**
	 * Whether this organisation is permitted to start a login with this
	 * provider — a SECRET-FREE policy question.
	 *
	 * Separated from {@see resolveOidcConfig()} deliberately. That method
	 * answers two questions at once and returns null for both: "is this
	 * org+provider allowed" and "give me the client secret". A public entry
	 * point should state its authorisation decision on its own, before any
	 * secret-bearing resolution, so the policy has a name, can be tested
	 * alone, and is legible to a reader who does not follow the resolver two
	 * frames down.
	 *
	 * Grants nothing new: it is exactly the conditions `resolveOidcConfig()`
	 * already applied — the provider must be in the closed
	 * {@see self::OIDC_PROVIDERS} allow-list, the organisation must resolve,
	 * and it must have declared this provider. Callers still fail closed on
	 * the resolver afterwards, so a change here cannot widen access on its
	 * own.
	 *
	 * @param string $orgSlug  The `?org=` slug.
	 * @param string $provider One of `digid|eherkenning|eidas|generic`.
	 *
	 * @return bool Whether a login may be started.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#oidc-start-builds-a-state-nonce-pkce-authorization-request
	 */
	public function isLoginProviderAllowed(string $orgSlug, string $provider): bool;

	/**
	 * Resolve the white-label presentation for a tenant, by slug.
	 *
	 * An empty slug, an unknown slug, or an unreachable OpenRegister all
	 * resolve to the same neutral default (design.md) — never a 500, never
	 * another tenant's branding.
	 *
	 * @param string $orgSlug The `?org=` query parameter value (may be empty).
	 * @param string $locale The resolved visitor locale (portal-spa-i18n-locale-support),
	 *                       e.g. from `Accept-Language`; an unsupported value
	 *                       falls back to `nl`.
	 *
	 * @return array<string, mixed> `{organisationName, organisationSlug, organisationId, theme,
	 *                              logo, featureFlags, allowedEmbedOrigins,
	 *                              apiBase, audience, locale, oidcProviders,
	 *                              silentSignIn}`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T09
	 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.2
	 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.3
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-the-public-site-must-render-its-own-words-through-a-locale-aware-translation-layer
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T01
	 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-organisation-filter-reads-the-organisations-name
	 */
	public function resolve(string $orgSlug, string $locale = 'nl'): array;

	/**
	 * Resolve the FULL per-organisation OIDC broker config for one provider —
	 * issuer/clientId/clientSecret/scopes/claimMap/loaMap merged with the
	 * provider's preset. NEVER call this from a path that returns to the SPA
	 * (it carries the client secret) — only `SessionController`'s server-side
	 * OIDC start/callback handlers use it.
	 *
	 * Fails closed to null on: an unknown org slug, an unconfigured provider,
	 * a missing required field (`issuer`/`clientId`/`clientSecret`), or an
	 * unrecognised provider preset — `oidcStart()`/`oidcCallback()` treat null
	 * identically to every other fail-closed path (same generic error).
	 *
	 * @param string $orgSlug The `?org=` slug.
	 * @param string $provider One of `digid|eherkenning|eidas|generic`.
	 *
	 * @return array<string, mixed>|null The merged OIDC config, or null.
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T01
	 * @spec openspec/specs/supplier-portal/spec.md#oidc-start-builds-a-state-nonce-pkce-authorization-request
	 */
	public function resolveOidcConfig(string $orgSlug, string $provider): ?array;

	/**
	 * The organisation's government message box channel: the integriq digital
	 * post source to send over and the label residents read, or null when the
	 * organisation does not offer the channel.
	 *
	 * Both values must be non-empty text. Half a configuration is no channel,
	 * so an organisation that set only one of them sends nothing and shows its
	 * residents no choice (REQ-MBC-001).
	 *
	 * @param string $orgSlug The organisation slug.
	 *
	 * @return array{sourceId: string, label: string}|null
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-organisation-turns-the-channel-on-req-mbc-001
	 */
	public function messageBox(string $orgSlug): ?array;
}//end interface
