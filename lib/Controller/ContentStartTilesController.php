<?php

/**
 * Portaliq Content Start Tiles Controller
 *
 * The public start tiles of a portal ("Direct regelen", site-nlds-widget-palette
 * design D6): every action that offers itself with a summary, as label,
 * summary, audiences and route. Readable signed out; choosing a tile leads
 * into the signed-in area, which asks a signed-out visitor to sign in first.
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
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves a portal's start tiles.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */
class ContentStartTilesController extends Controller {
	/**
	 * The keys a tile may carry; anything else never leaves.
	 */
	private const TILE_KEYS = ['label', 'summary', 'audiences', 'route'];

	/**
	 * Constructor.
	 *
	 * @param IRequest  The request.
	 * @param PortalContributionRegistry  Reads the contributions.
	 * @param PortalResolver  Resolves the serving portal.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalResolver $resolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The start tiles of a portal.
	 *
	 * @param string|null $portal The portal slug; the host decides when empty.
	 *
	 * @return JSONResponse `{tiles: [{label, summary, audiences, route}]}`, or 404.
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(?string $portal = null): JSONResponse {
		if ($this->resolver->resolve(request: $this->request, portalSlug: $portal) === null) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->addHeader('Cache-Control', 'private, no-store');
			return $response;
		}

		$tiles = [];
		foreach ($this->registry->startTiles() as $tile) {
			$tiles[] = array_intersect_key($tile, array_flip(self::TILE_KEYS));
		}

		$response = new JSONResponse(['tiles' => $tiles]);
		$response->addHeader('Cache-Control', 'public, max-age=300, must-revalidate');

		return $response;
	}//end index()
}//end class
