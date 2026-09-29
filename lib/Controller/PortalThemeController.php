<?php

/**
 * The theme picker behind the portal's Theme widget.
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
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists the sets a portal can wear and saves the one chosen.
 */
class PortalThemeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param PortalThemeChoice $choice  The sets and the choice.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalThemeChoice $choice,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The portal's theme and every set it can adopt, with contrast verdicts.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only A portal's house style is an administrator's choice.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function index(string $slug): JSONResponse {
		$portal = $this->choice->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->choice->listFor(portal: $portal));
	}//end index()

	/**
	 * Save the portal's theme.
	 *
	 * The administrator's confirmation of a set that fails contrast arrives
	 * as the `acceptFindings` body field and selects the confirming save.
	 *
	 * @param string $slug  The portal slug.
	 * @param string $theme The set id.
	 *
	 * @return JSONResponse The list as stored; 404 for an unknown portal; 422
	 *                      for a set that does not resolve, or one that fails
	 *                      contrast without confirmation; 502 when the write failed.
	 *
	 * @auth admin-only A portal's house style is an administrator's choice.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function update(string $slug, string $theme = ''): JSONResponse {
		$portal = $this->choice->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->choice->choose(portal: $portal, theme: $theme);
		if (filter_var($this->request->getParam('acceptFindings', false), FILTER_VALIDATE_BOOLEAN) === true) {
			$result = $this->choice->chooseConfirmingFindings(portal: $portal, theme: $theme);
		}
		if (($result['error'] ?? null) === 'save_failed') {
			return new JSONResponse($result, Http::STATUS_BAD_GATEWAY);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($this->choice->listFor(portal: $result['portal']));
	}//end update()
}//end class
