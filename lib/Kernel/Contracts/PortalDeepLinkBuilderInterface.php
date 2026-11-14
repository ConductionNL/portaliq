<?php

/**
 * Contract of PortalDeepLinkBuilder
 *
 * The public surface of PortalDeepLinkBuilder that other components call, so they depend
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
 * Contract of PortalDeepLinkBuilder.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalDeepLinkBuilderInterface {

	/**
	 * The link a notice carries: the record when the notice is about one,
	 * else the portal of the tenant.
	 *
	 * @param string                $organisation The tenant slug.
	 * @param array<string, string> $record       The record ({app, collection, id}), or [].
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-notification-leads-to-the-record-req-nap-005
	 */
	public function forNotice(string $organisation, array $record): string;

	/**
	 * The absolute portal URL for a tenant, or the bare portal when the
	 * tenant is unknown ('' — the neutral default the portal then renders).
	 *
	 * @param string $organisation The tenant identifier the session carries.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
	 */
	public function forOrganisation(string $organisation): string;

	/**
	 * The absolute link to one portal's site, `?portal=<slug>` (the ways in
	 * of identity-ways-in-screens). Without a slug it falls back to the
	 * organisation's link, `?org=`, which the site resolves too. The same
	 * address as forPortal() since `/portal` became a redirect to the site;
	 * kept so its callers keep their names.
	 *
	 * @param string $portalSlug   The portal's slug, or ''.
	 * @param string $organisation The tenant slug, for the fallback.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	public function forSite(string $portalSlug, string $organisation = ''): string;
}//end interface
