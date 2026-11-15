<?php

/**
 * Contract of AccessibilityFraming
 *
 * The public surface of AccessibilityFraming that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IRequest;

/**
 * Contract of AccessibilityFraming.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccessibilityFramingInterface {

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
	public function sitePolicy(IRequest $request): ContentSecurityPolicy;
}//end interface
