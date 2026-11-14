<?php

/**
 * The status panel on a form's settings page: the submissions of the
 * binding's current period against its limit.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Portaliq.app
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\Intake\FormBindingCounter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers a binding's counter, for an administrator.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
 */
class FormBindingCounterController extends Controller {

	/**
	 * Wire the controller.
	 *
	 * @param IRequest           $request The request.
	 * @param FormBindingCounter $counter Counts a binding's current period against its limit.
	 */
	public function __construct(
		IRequest $request,
		private readonly FormBindingCounter $counter,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The submissions of this binding's current period against its limit.
	 *
	 * @param string $id The binding's id.
	 *
	 * @return JSONResponse `{limited, count, limit, per}`; `limited` false and
	 *                      the rest null when the binding sets no limit; 404 for
	 *                      an unknown binding.
	 *
	 * @auth admin-only The count reads the destination past any resident's
	 *       scope, which is an administrator's view. Nextcloud expresses
	 *       admin-only as the ABSENCE of an opt-out attribute, so this tag is
	 *       the declaration.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
	 */
	public function show(string $id): JSONResponse {
		$result = $this->counter->forBinding(id: $id);
		if ($result['found'] === false) {
			return new JSONResponse(['error' => 'binding_not_found'], Http::STATUS_NOT_FOUND);
		}

		unset($result['found']);

		return new JSONResponse($result);
	}//end show()
}//end class
