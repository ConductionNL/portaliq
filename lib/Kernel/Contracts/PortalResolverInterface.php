<?php

/**
 * Contract of PortalResolver
 *
 * The public surface of PortalResolver that other components call, so they depend
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
 * Contract of PortalResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalResolverInterface {

	/**
	 * Every published portal.
	 *
	 * @return array The published portal objects.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function allPublishedPortals(): array;

	/**
	 * Resolve the portal for a request.
	 *
	 * @param IRequest    $request  The incoming request.
	 * @param string|null $portalSlug An explicit site slug, when the caller named one.
	 *
	 * @return array|null The portal object, or null when nothing matched.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function resolve(IRequest $request, ?string $portalSlug = null): ?array;

	/**
	 * Resolve the portal an `?org=` value names — an ALIAS, never a key.
	 *
	 * `portal.organisation` is not unique and is not meant to be. The product
	 * decision of 2026-09-22 (WOO-566) is explicit: one organisation may run
	 * several portals that look different from each other. So the only answer
	 * this method can give honestly is the unambiguous one — EXACTLY ONE
	 * published portal carries the value.
	 *
	 * At zero matches the organisation is unknown here. At two or more the
	 * question "which branding belongs to this organisation" genuinely has no
	 * answer, and the tempting shortcut — take the first, or the newest —
	 * would serve one tenant's brand under another tenant's name. That failure
	 * is invisible from inside the request: the page renders, the colours are
	 * a real municipality's, and nothing looks wrong to anyone who is not
	 * already the wronged tenant.
	 *
	 * Both misses therefore return null and the caller falls back to the
	 * neutral default, which is the same posture `resolve()` takes for an
	 * unknown slug.
	 *
	 * @param string $organisation The `?org=` value.
	 *
	 * @return array|null The single matching portal, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function resolveByOrganisation(string $organisation): ?array;

	/**
	 * Resolve the portal for the traffic collector: HOST first, slug second.
	 *
	 * The reverse of `resolve()`, deliberately. A collector that honoured a
	 * named slug over the host would let any page attribute its events to
	 * any portal it cared to name. The slug is only consulted when the host
	 * resolves to nothing, which is the case for a `kind: external` portal
	 * on its own domain posting to the platform host.
	 *
	 * @param IRequest    $request    The incoming request.
	 * @param string|null $portalSlug The slug the caller named, if any.
	 *
	 * @return array|null The portal object, or null when nothing matched.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
	 */
	public function resolveForCollector(IRequest $request, ?string $portalSlug = null): ?array;

	/**
	 * @param array<string, mixed>|null $portal The serving portal, or null when it could not be resolved.
	 * @param string                    $locale The visitor's language, never empty.
	 *
	 * @return string The language to serve.
	 *
	 * The visitor's language held against the languages the portal declares
	 * (`locales`, site-matches-the-zuiddrecht-boards). A portal that declares
	 * only `nl` serves `nl` to a browser that asks for English: the document
	 * language, and so every date the widgets print, follow the portal. A
	 * portal that declares nothing, or null, serves what the visitor asked for.
	 *
	 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
	 */
	public function localeFor(?array $portal, string $locale): string;

	/**
	 * The verified domain entry this request is served on, or null.
	 *
	 * A domain carries settings of its own (whether its host serves the site
	 * at its root, and the page its root opens). Those settings belong to the
	 * host, so they are read ONLY when the host itself decides the portal:
	 * the request host matches a verified domain, and the caller named either
	 * no portal or that same portal. A request that names another portal by
	 * slug is served as that portal and gets no domain, so the settings of
	 * one portal's host never shape another portal's pages.
	 *
	 * An unknown host and an unverified domain both answer null, exactly as
	 * they resolve to no portal.
	 *
	 * @param IRequest    $request    The incoming request.
	 * @param string|null $portalSlug The slug the caller named, if any.
	 *
	 * @return array<string, mixed>|null The domain entry, or null.
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
	 */
	public function servingDomain(IRequest $request, ?string $portalSlug = null): ?array;
}//end interface
