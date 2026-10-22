<?php

/**
 * The Berichtenbox switch on a portal's settings tab.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Notifications\MessageBoxSetting;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads and switches whether the portal's organisation offers the message box.
 */
class PortalMessageBoxController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param PortalThemeChoice $portals Finds the portal by its slug.
	 * @param MessageBoxSetting $setting The organisation's message box offer.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalThemeChoice $portals,
		private readonly MessageBoxSetting $setting,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the portal's organisation offers the message box.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse `{offered, configured, label}`; 404 for an unknown portal or organisation.
	 *
	 * @auth admin-only Which channels an organisation offers is an administrator's choice.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out attribute, so this
	 *       tag is the declaration.
	 *
	 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
	 */
	public function index(string $slug): JSONResponse {
		$state = $this->setting->read(orgSlug: $this->organisationOf(slug: $slug));
		if ($state === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($state);
	}//end index()

	/**
	 * Switch the offer on or off, as the `offered` body field says.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse The new state; 404, 422 `no_source` or 502 `save_failed`.
	 *
	 * @auth admin-only Which channels an organisation offers is an administrator's choice.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out attribute, so this
	 *       tag is the declaration.
	 *
	 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
	 */
	public function update(string $slug): JSONResponse {
		$offered = filter_var($this->request->getParam('offered', false), FILTER_VALIDATE_BOOLEAN);
		$result  = $this->setting->write(orgSlug: $this->organisationOf(slug: $slug), offered: $offered);
		if (is_array($result) === true) {
			return new JSONResponse($result);
		}

		$status = match ($result) {
			'not_found' => Http::STATUS_NOT_FOUND,
			'no_source' => Http::STATUS_UNPROCESSABLE_ENTITY,
			default => Http::STATUS_BAD_GATEWAY,
		};

		return new JSONResponse(['error' => $result], $status);
	}//end update()

	/**
	 * The organisation slug of the portal with this slug, or an empty string.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return string The organisation slug.
	 */
	private function organisationOf(string $slug): string {
		return (string)(($this->portals->portalBySlug(slug: $slug) ?? [])['organisation'] ?? '');
	}//end organisationOf()
}//end class
