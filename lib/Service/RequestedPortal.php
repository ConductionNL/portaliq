<?php

/**
 * Portaliq requested portal
 *
 * The portal slug a request names: the site sends it as the
 * `X-Portaliq-Portal` header, older callers as the `portal` query parameter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\RequestedPortalInterface;
use OCP\IRequest;

/**
 * Reads the portal slug a request names.
 */
class RequestedPortal implements RequestedPortalInterface {

	/**
	 * The header the site names its portal in.
	 *
	 * @var string
	 */
	public const HEADER = 'X-Portaliq-Portal';

	/**
	 * The slug the request names: the header, else `?portal=`, else null.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string|null The slug, or null when the request names none.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function slug(IRequest $request): ?string {
		$slug = trim($request->getHeader(self::HEADER));
		if ($slug === '') {
			$param = $request->getParam('portal');
			$slug  = '';
			if (is_string($param) === true) {
				$slug = trim($param);
			}
		}

		if ($slug === '') {
			return null;
		}

		return $slug;
	}//end slug()
}//end class
