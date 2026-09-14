<?php

/**
 * Portaliq Portal Deep Link Builder
 *
 * The ONE place a mail (task delivery, notification dispatch) turns "send the
 * resident to the portal" into an absolute URL. Built from the route table
 * (`linkToRoute('portaliq.portalPage.index')` + `getAbsoluteURL()`), never
 * from a bare path: `getAbsoluteURL('/portal')` produced `https://host/portal`,
 * a path that exists on no deployment at all (the route is
 * `/apps/portaliq/portal`, with `index.php` in front on instances without
 * pretty URLs), so the only call-to-action in every notification mail was a
 * 404 (WOO-570).
 *
 * The tenant parameter is passed through as `?org=<organisation>` exactly as
 * the two jobs did before this class existed. Which tenant identifier the
 * public portal should resolve on (OpenRegister organisation slug vs. portal
 * object) is WOO-566's decision and is deliberately NOT changed here.
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
 * Builds the absolute deep link into the public portal for out-of-band mail.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */
class PortalDeepLinkBuilder {
	/**
	 * The public portal shell's route name (appinfo/routes.php `portalPage#index`).
	 */
	private const PORTAL_ROUTE = 'portaliq.portalPage.index';

	/**
	 * The query parameter the portal shell reads the tenant from
	 * (PortalPageController::index(), `?org=`). See WOO-566 before changing it.
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

		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(self::PORTAL_ROUTE, $parameters));
	}//end forOrganisation()
}//end class
