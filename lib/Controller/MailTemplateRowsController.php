<?php

/**
 * Portaliq Mail Template Rows Controller (mail-templates-admin-screen)
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
 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\Mail\MailTemplateList;
use OCA\Portaliq\Settings\PortaliqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The rows of the Mail templates screen: every kind of mail, merged with the
 * texts portals stored for it.
 *
 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t09
 */
class MailTemplateRowsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest         $request The request.
	 * @param MailTemplateList $list    The screen's rows.
	 */
	public function __construct(
		IRequest $request,
		private readonly MailTemplateList $list,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One row per kind of mail, or one per stored text of that kind, with its
	 * subject, variable count and change date.
	 *
	 * @return JSONResponse `results` and `total`.
	 *
	 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t09
	 */
	#[AuthorizedAdminSetting(PortaliqAdmin::class)]
	public function index(): JSONResponse {
		$rows = $this->list->rows();

		return new JSONResponse(data: ['results' => $rows, 'total' => count($rows)]);
	}//end index()
}//end class
