<?php

/**
 * Contract of PortalRuntimeConfigResolver
 *
 * The public surface of PortalRuntimeConfigResolver that other components call, so they depend
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

use OCP\IRequest;

/**
 * Contract of PortalRuntimeConfigResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRuntimeConfigResolverInterface {

	/**
	 * Which portal serves this request.
	 *
	 * THREE ways in, in this order, and the order is a security decision:
	 *
	 *   1. `?portal=<slug>` — the explicit, primary parameter, identical to
	 *      `/site`. A named portal that does not exist is a MISS, never an
	 *      invitation to fall through to something else; otherwise
	 *      `?portal=typo` would quietly serve whichever portal owns the
	 *      hostname.
	 *   2. `?org=<value>` — the alias kept for existing links (WOO-570's mail
	 *      deeplink builds one). Deterministic and strict: see
	 *      {@see PortalResolver::resolveByOrganisation()}.
	 *   3. Neither given — the request host, matched against VERIFIED domains.
	 *
	 * An explicitly named tenant that misses does NOT fall through to host
	 * matching. Naming a tenant and getting a different one is worse than
	 * naming a tenant and getting none.
	 *
	 * @param IRequest $request    The incoming request.
	 * @param string   $portalSlug The `?portal=` value (may be empty).
	 * @param string   $orgValue   The `?org=` value (may be empty).
	 *
	 * @return array|null The serving portal, or null when nothing resolved.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function resolvePortal(IRequest $request, string $portalSlug, string $orgValue): ?array;

	/**
	 * The runtime config for a resolved portal (or for none).
	 *
	 * The NEUTRAL DEFAULT IS THE FLOOR, always. It is fetched from
	 * `PortalOrganisationConfigService::resolve('')`, whose documented
	 * behaviour for an empty slug is exactly that: the complete, tenant-free
	 * config shape with the visitor's locale applied. Every branch below can
	 * only overwrite individual keys of it, so the shape the SPA receives
	 * cannot go missing a field no matter which path produced it.
	 *
	 * NOTE ON WHAT IS **NOT** LAYERED IN: the old OpenRegister Organisation
	 * lookup. It is gone from the branding path rather than kept as a
	 * fallback beneath the portal, and that was a deliberate call rather than
	 * an oversight — an org-wide code search found two references to
	 * `org_presentation_` in the entire fleet, both inside this app (the
	 * reader and one e2e spec), and no writer anywhere. A fallback layer that
	 * can never find anything is not a safety net, it is code that has to be
	 * read, tested and maintained forever to do nothing.
	 *
	 * @param array|null $portal   The serving portal, or null.
	 * @param string     $orgValue The `?org=` value, for the OIDC lookup.
	 * @param string     $locale   The visitor's resolved locale.
	 *
	 * @return array<string, mixed> The SPA runtime config.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function runtimeConfigFor(?array $portal, string $orgValue, string $locale): array;
}//end interface
