<?php

/**
 * Portaliq shared block controller (site-shared-page-blocks)
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
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PageEditorServiceInterface;
use OCA\Portaliq\Kernel\Contracts\SharedBlockPlacementsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The pages that place a shared block, for the block's detail page.
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
 */
class SharedBlockController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request    The request.
	 * @param PageEditorServiceInterface     $pageEditor Who may edit pages, and so blocks.
	 * @param SharedBlockPlacementsInterface $placements The pages that place a block.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
	 */
	public function __construct(
		IRequest $request,
		private readonly PageEditorServiceInterface $pageEditor,
		private readonly SharedBlockPlacementsInterface $placements,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The pages whose published layout places the block.
	 *
	 * Only a page editor asks: the list names pages that may not be public
	 * (a draft page, a page for signed-in visitors). A block the editor
	 * cannot read answers 404, the same as one that does not exist.
	 *
	 * @param string $id The shared block's id or uuid.
	 *
	 * @return JSONResponse `{results: [...]}`, or 403 / 404.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
	 */
	#[NoAdminRequired]
	public function pages(string $id): JSONResponse {
		if ($this->pageEditor->mayEdit() === false) {
			return new JSONResponse(['error' => 'not_an_editor'], Http::STATUS_FORBIDDEN);
		}

		$pages = $this->placements->pagesOf(blockId: $id);
		if ($pages === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$response = new JSONResponse(['results' => $pages, 'total' => count($pages)]);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end pages()
}//end class
