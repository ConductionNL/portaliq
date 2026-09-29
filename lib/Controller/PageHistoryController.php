<?php

/**
 * Portaliq page history controller
 *
 * The published versions of a page, for the page designer's History dialog.
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
use OCA\Portaliq\Service\Cms\PageHistory;
use OCA\Portaliq\Service\PageEditorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads a page's history for a page editor.
 *
 * Restoring is not here: the designer writes the chosen version into the
 * page's draft through OpenRegister, where the page schema's write rules
 * decide, exactly as for any draft save.
 */
class PageHistoryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request    The request.
	 * @param PageEditorService $pageEditor Decides who is a page editor.
	 * @param PageHistory       $history    The versions.
	 */
	public function __construct(
		IRequest $request,
		private readonly PageEditorService $pageEditor,
		private readonly PageHistory $history,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The published versions of a page, newest first.
	 *
	 * @param string $id The page object's uuid.
	 *
	 * @return JSONResponse `{versions}`; 403 for a caller who may not edit pages.
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		// The guard: a version carries the page's full content, including
		// content that was never public (a page gated to signed-in visitors).
		if ($this->pageEditor->mayEdit() === false) {
			return new JSONResponse(['error' => 'not_an_editor'], Http::STATUS_FORBIDDEN);
		}

		$response = new JSONResponse(['versions' => $this->history->versions(pageId: $id)]);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end index()
}//end class
