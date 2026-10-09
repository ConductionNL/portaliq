<?php

/**
 * The organisation type picker behind the portal's identity widget.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\OrganisationTypeOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers the TOOI kinds of organisation an administrator can pick.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class OrganisationTypeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request The request.
	 * @param OrganisationTypeOptions $options The kinds, from the concept register.
	 */
	public function __construct(
		IRequest $request,
		private readonly OrganisationTypeOptions $options,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The kinds of organisation, or `installed: false` when the TOOI list is
	 * not in the concept register.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Naming the organisation is a portal setting, which an administrator sets.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function index(): JSONResponse {
		return new JSONResponse($this->options->options());
	}//end index()
}//end class
