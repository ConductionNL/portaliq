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
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
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
	 * @param IL10N                $l10n     Words the outcome of a check.
	 * @param PortalAdminScope     $scope    Who may administer which portal.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalDomainVerifier $verifier,
		private readonly IL10N $l10n,
		private readonly PortalAdminScope $scope,
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
	 * @auth per-portal An instance administrator, or a member of one of the
	 *       portal's adminGroups; any other user gets 404 (REQ-OPO-005).
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function records(string $portal = ''): JSONResponse {
		if ($this->scope->mayAdministerSlug(slug: $portal) === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

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
	 * @return JSONResponse `{status: verified|pending, message}`, or 404 for an unknown portal or domain.
	 *                      The message is the sentence the Domeinen list shows after "Check again".
	 *
	 * @auth per-portal An instance administrator, or a member of one of the
	 *       portal's adminGroups; any other user gets 404 (REQ-OPO-005).
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function verify(string $portal = '', string $hostname = ''): JSONResponse {
		if ($this->scope->mayAdministerSlug(slug: $portal) === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$status = $this->verifier->verify(slug: $portal, hostname: $hostname);
		if ($status === null || $status === PortalDomainVerifier::NOT_FOUND) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$message = $this->l10n->t('The record of %s is not there yet.', [$hostname]);
		if ($status === PortalDomainVerifier::VERIFIED) {
			$message = $this->l10n->t('%s is verified.', [$hostname]);
		}

		return new JSONResponse(['status' => $status, 'message' => $message]);
	}//end verify()
}//end class
