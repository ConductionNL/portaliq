<?php

/**
 * Portaliq Portal Deep Link Builder
 *
 * The ONE place a mail (task delivery, notification dispatch) turns "send the
 * resident to the portal" into an absolute URL. Built from the route table
 * (`linkToRoute('portaliq.portalPage.site')` + `getAbsoluteURL()`), never
 * from a bare path: `getAbsoluteURL('/portal')` produced `https://host/portal`,
 * a path that exists on no deployment at all, so the only call-to-action in
 * every notification mail was a 404 (WOO-570).
 *
 * Every link opens the Vue site (`/site`). It opened the React portal
 * (`/portal`) until the site replaced it (site-reaches-portal-parity
 * REQ-SRP-049); `/portal` now redirects, so a mail sent before keeps working,
 * but no new mail depends on that redirect.
 *
 * The tenant parameter is passed through as `?org=<organisation>`, which the
 * site resolves to the organisation's one published portal, exactly as the
 * React portal did. Which tenant identifier a mail should carry (organisation
 * vs. portal) is WOO-566's decision and is deliberately NOT changed here.
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
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IURLGenerator;

/**
 * Builds the absolute deep link into the site for out-of-band mail.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */
class PortalDeepLinkBuilder {
	/**
	 * The site's route name (appinfo/routes.php `portalPage#site`), where
	 * every mail link lands (site-reaches-portal-parity REQ-SRP-049).
	 */
	private const SITE_ROUTE = 'portaliq.portalPage.site';

	/**
	 * The query parameter the site reads a named portal from
	 * (PortalPageController::site(), `?portal=`). It wins over `?org=`, and a
	 * slug that names no portal is a miss, never a fallback.
	 */
	private const PORTAL_PARAMETER = 'portal';

	/**
	 * The query parameter the site reads the tenant from
	 * (PortalPageController::site(), `?org=`). See WOO-566 before changing it.
	 */
	private const TENANT_PARAMETER = 'org';

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Resolves the portal route to an absolute URL.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The absolute portal URL for a tenant, or the bare portal when the
	 * tenant is unknown ('' — the neutral default the portal then renders).
	 *
	 * @param string $organisation The tenant identifier the session carries.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
	 */
	public function forOrganisation(string $organisation): string {
		$parameters = [];
		if ($organisation !== '') {
			$parameters[self::TENANT_PARAMETER] = $organisation;
		}

		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(self::SITE_ROUTE, $parameters));
	}//end forOrganisation()

	/**
	 * The absolute URL of one named portal (`?portal=<slug>`).
	 *
	 * For a mail that belongs to a portal rather than to a tenant: one
	 * organisation may run several portals, and `?org=` names none of them
	 * when it does (WOO-566). An empty slug falls back to the tenant link.
	 *
	 * @param string $portalSlug The portal's slug.
	 * @param string $organisation The tenant, used only when the slug is ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function forPortal(string $portalSlug, string $organisation = ''): string {
		if ($portalSlug === '') {
			return $this->forOrganisation(organisation: $organisation);
		}

		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(self::SITE_ROUTE, [self::PORTAL_PARAMETER => $portalSlug]));
	}//end forPortal()

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
	public function forSite(string $portalSlug, string $organisation = ''): string {
		if ($portalSlug === '') {
			return $this->forOrganisation(organisation: $organisation);
		}

		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(self::SITE_ROUTE, [self::PORTAL_PARAMETER => $portalSlug]));
	}//end forSite()

	/**
	 * The portal address that opens one record (REQ-NAP-005).
	 *
	 * The record rides in the fragment, `#open=<app>/<collection>/<id>`, which
	 * the portal reads and strips on load and keeps through a sign-in. A
	 * fragment never reaches the server, so a record id stays out of access
	 * logs, and the portal still reads the record through the resident's own
	 * scoped read: a forwarded link opens nothing of someone else's record.
	 *
	 * @param string $organisation The tenant slug.
	 * @param string $app          The contributing app.
	 * @param string $collection   The collection id.
	 * @param string $id           The record id.
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-notification-leads-to-the-record-req-nap-005
	 */
	public function forRecord(string $organisation, string $app, string $collection, string $id): string {
		$base = $this->forOrganisation(organisation: $organisation);
		if ($app === '' || $collection === '' || $id === '') {
			return $base;
		}

		return $base.'#open='.rawurlencode($app).'/'.rawurlencode($collection).'/'.rawurlencode($id);
	}//end forRecord()

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
	public function forNotice(string $organisation, array $record): string {
		return $this->forRecord(
			organisation: $organisation,
			app: (string)($record['app'] ?? ''),
			collection: (string)($record['collection'] ?? ''),
			id: (string)($record['id'] ?? '')
		);
	}//end forNotice()
}//end class
