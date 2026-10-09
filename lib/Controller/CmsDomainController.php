<?php

/**
 * Portaliq CMS Domain Controller (portal-cms-admin-ui)
 *
 * The record a portal's administrator must publish to prove a domain, and
 * the button that checks it. Admin only (no `NoAdminRequired`): a domain
 * decides which hostname serves whose content.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Shows the TXT record of a portal's domains and checks it.
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */
class CmsDomainController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string               $appName  The app name.
	 * @param IRequest             $request  The request.
	 * @param PortalDomainVerifier $verifier Shows and checks the records.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalDomainVerifier $verifier,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The record each domain of a portal must publish.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return JSONResponse `{domains: [{hostname, name, value, verified}]}`, or 404.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	#[NoCSRFRequired]
	public function records(string $portal = ''): JSONResponse {
		$records = $this->verifier->records(slug: $portal);
		if ($records === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['domains' => $records]);
	}//end records()

	/**
	 * Check one domain's record. A record that is not there yet answers
	 * `pending`, which is the normal first outcome; the check can be run again.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $hostname The domain to check.
	 *
	 * @return JSONResponse `{status: verified|pending}`, or 404 for an unknown portal or domain.
	 *
	 * @auth admin-only because a domain decides which hostname serves whose content, so only an administrator may run the check
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	public function verify(string $portal = '', string $hostname = ''): JSONResponse {
		$status = $this->verifier->verify(slug: $portal, hostname: $hostname);
		if ($status === null || $status === PortalDomainVerifier::NOT_FOUND) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['status' => $status]);
	}//end verify()
}//end class
