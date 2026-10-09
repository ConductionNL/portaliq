<?php

/**
 * Whether the site may be framed by its own origin for one request: the
 * accessibility measurement loads each page in a frame in the
 * administrator's browser (site-accessibility-statement REQ-SAS-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Controller\AccessibilityController;
use OCA\Portaliq\Service\ActionAuthService;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The site is never framed, with one exception: a request that asks for the
 * measurement (`?measure=1`) from a signed-in user the action matrix allows
 * to measure. Only the same origin may frame it then, so a page elsewhere
 * still cannot.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
class AccessibilityFraming {

	/**
	 * @param IUserSession      $userSession The signed-in user.
	 * @param ActionAuthService $actionAuth  The action matrix.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
	}//end __construct()

	/**
	 * The site's content security policy: never framed unless allowsSelf(),
	 * and the NLDS webfonts from Google's font CDN (a blocked font renders
	 * the portal in a fallback face while every token says otherwise).
	 *
	 * @param IRequest $request The request for the site.
	 *
	 * @return ContentSecurityPolicy
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function sitePolicy(IRequest $request): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		// Clear the `'self'` default first, or a site with no configured
		// embedders still allows same-origin framing.
		$csp->disallowFrameAncestorDomain('\'self\'');
		if ($this->allowsSelf(request: $request) === true) {
			$csp->addAllowedFrameAncestorDomain('\'self\'');
		}

		$csp->addAllowedFontDomain('https://fonts.gstatic.com');
		$csp->addAllowedStyleDomain('https://fonts.googleapis.com');

		return $csp;
	}//end sitePolicy()

	/**
	 * Whether this request may be framed by the same origin.
	 *
	 * @param IRequest $request The request for the site.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function allowsSelf(IRequest $request): bool {
		if ((string)$request->getParam('measure', '') !== '1') {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->actionAuth->can(user: $user, action: AccessibilityController::ACTION_MEASURE);
	}//end allowsSelf()
}//end class
