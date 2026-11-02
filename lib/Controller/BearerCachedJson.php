<?php

/**
 * Portaliq Bearer Cached Json
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
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCP\AppFramework\Http\JSONResponse;

/**
 * A content answer that a CDN may cache only when no bearer came with it.
 *
 * The using controller provides `$request`.
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
trait BearerCachedJson {
	/**
	 * A content response: cacheable for a visitor without a bearer, private otherwise.
	 *
	 * @param array<string, mixed> $payload The body.
	 *
	 * @return JSONResponse
	 */
	private function publicJson(array $payload): JSONResponse {
		$response = new JSONResponse($payload);
		$cache    = 'private, no-store';
		if ((string)$this->request->getHeader('Authorization') === '') {
			$cache = 'public, max-age=300, must-revalidate';
		}

		$response->addHeader('Cache-Control', $cache);

		return $response;
	}//end publicJson()
}//end trait
