<?php

/**
 * The admin's answer to "which form does this entry open today?".
 *
 * A form binding can be perfectly well-formed while the form it points at is
 * unpublished, published to another audience, or gone. The Form bindings page
 * shows the binding as configured, which reads the same either way, so an
 * administrator asks this endpoint and gets the form the binding resolves to
 * RIGHT NOW, through the same resolution the citizen-facing render uses.
 *
 * The body is the binding row the admin is looking at, so a draft binding can
 * be checked before it is published.
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
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Intake\PortalBindingPreview;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Previews what a form binding resolves to, for an administrator.
 *
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */
class FormBindingAdminController extends Controller {

	/**
	 * Wire the controller.
	 *
	 * @param IRequest             $request The request.
	 * @param PortalBindingPreview $preview What a binding resolves to today.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalBindingPreview $preview,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * What this binding resolves to today.
	 *
	 * @param array<string, mixed> $binding The binding row as the admin sees it.
	 *
	 * @return JSONResponse The preview: state, formName, reason, askedFor,
	 *                      destination and an English message.
	 *
	 * @auth admin-only Resolving a binding reads the form register past the
	 *       portal's audience scoping, which is an administrator's view. Nextcloud
	 *       expresses admin-only as the ABSENCE of an opt-out attribute, so this
	 *       tag is the declaration.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
	 */
	public function preview(array $binding = []): JSONResponse {
		if ($binding === []) {
			return new JSONResponse(['error' => 'binding_required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->preview->describe(binding: $binding));
	}//end preview()
}//end class
