<?php

/**
 * Portaliq content media controller
 *
 * The public address of a media library item: GET /api/content/media/{id}.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Cms\MediaFile;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves a published media item of the serving portal.
 *
 * Public on purpose: a page's images are part of the public page. Every miss
 * (no portal, a draft item, another portal's item, an unknown id, an item
 * without a file) is the same 404, so the route tells nobody what exists.
 */
class ContentMediaController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request  The request.
	 * @param PortalResolver $resolver Resolves the serving portal, as the content API does.
	 * @param MediaFile      $media    The item's file.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly MediaFile $media,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Stream the item's file.
	 *
	 * @param string      $id     The item id.
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 *
	 * @return Response The file, or 404.
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function show(string $id, ?string $portal = null): Response {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved !== null) {
			$stream = $this->media->stream(portal: $resolved, id: $id);
			if ($stream !== null) {
				return $stream;
			}
		}

		$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end show()
}//end class
