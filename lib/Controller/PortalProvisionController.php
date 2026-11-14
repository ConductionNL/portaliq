<?php

/**
 * Portaliq Portal Provision Controller (operate-portals-per-organisation)
 *
 * The "New portal" dialog on the Portals admin page posts here: the same
 * provisioning as `occ portaliq:portal:provision`, a draft portal for an
 * organisation with its host, a menu and a home page, and the DNS record that
 * verifies the host. Instance administrators only: handing an organisation a
 * portal is the hosting party's act (REQ-OPO-005).
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\Contracts\PortalProvisioningServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Provisions an organisation's draft portal from the admin page.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
class PortalProvisionController extends Controller {
	/**
	 * The status a refusal answers with.
	 */
	private const STATUS = [
		PortalProvisioningServiceInterface::INVALID     => Http::STATUS_BAD_REQUEST,
		PortalProvisioningServiceInterface::SLUG_TAKEN  => Http::STATUS_CONFLICT,
		PortalProvisioningServiceInterface::HOST_TAKEN  => Http::STATUS_CONFLICT,
		PortalProvisioningServiceInterface::UNAVAILABLE => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param string                             $appName      The app name.
	 * @param IRequest                           $request      The request.
	 * @param PortalProvisioningServiceInterface $provisioning Creates the draft portal.
	 * @param IL10N                              $l10n         Words the refusal.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalProvisioningServiceInterface $provisioning,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Create the draft portal of an organisation.
	 *
	 * @param string $organisation The organisation the portal belongs to.
	 * @param string $slug         The portal's slug.
	 * @param string $title        The portal's title.
	 * @param string $host         The host the portal answers on, without scheme or port.
	 *
	 * @return JSONResponse 201 `{status: created, slug, dns: {name, value}}`; a refusal answers
	 *                      `{error, message}` with 400 (invalid), 409 (slug or host taken),
	 *                      503 (OpenRegister absent) or 500 (not written).
	 *
	 * @auth admin-only Provisioning a portal is the hosting party's act, never a
	 *       portal administrator's (REQ-OPO-005). Nextcloud expresses "instance
	 *       admin + CSRF" as the absence of an opt-out attribute, so there is no
	 *       attribute to add; this tag is the declaration.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
	 */
	public function provision(string $organisation = '', string $slug = '', string $title = '', string $host = ''): JSONResponse {
		$result = $this->provisioning->provision(organisation: $organisation, slug: $slug, title: $title, host: $host);
		if ($result['status'] === PortalProvisioningServiceInterface::CREATED && $result['dns'] !== null) {
			return new JSONResponse(
				['status' => $result['status'], 'slug' => trim($slug), 'dns' => $result['dns']],
				Http::STATUS_CREATED
			);
		}

		$status = (self::STATUS[$result['status']] ?? Http::STATUS_INTERNAL_SERVER_ERROR);
		$error  = PortalProvisioningServiceInterface::FAILED;
		if (isset(self::STATUS[$result['status']]) === true) {
			$error = $result['status'];
		}

		return new JSONResponse(['error' => $error, 'message' => $this->message(error: $error)], $status);
	}//end provision()

	/**
	 * The sentence for a refusal.
	 *
	 * @param string $error The refusal code.
	 *
	 * @return string
	 */
	private function message(string $error): string {
		return match ($error) {
			PortalProvisioningServiceInterface::INVALID     => $this->l10n->t(
				'Give the organisation, a title, a slug of lower case letters, digits and hyphens, and a web address without https:// or a port.'
			),
			PortalProvisioningServiceInterface::SLUG_TAKEN  => $this->l10n->t('This portal slug is already used by another portal.'),
			PortalProvisioningServiceInterface::HOST_TAKEN  => $this->l10n->t('This web address is already used by another portal.'),
			PortalProvisioningServiceInterface::UNAVAILABLE => $this->l10n->t('OpenRegister is not available, so nothing was written.'),
			default => $this->l10n->t('The portal could not be written. Nothing else was created.'),
		};
	}//end message()
}//end class
