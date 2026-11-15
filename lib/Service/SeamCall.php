<?php

/**
 * Portaliq Seam Call
 *
 * One assertion-signed call to OpenRegister's portal task seam while
 * `PortalTaskGateway` makes it: the method and the seam route, the route
 * parameters, the multipart parts of a POST and the path the route resolves
 * to. It never leaves the gateway that builds it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One seam call in progress.
 *
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 */
final class SeamCall {

	/**
	 * Route parameters; anything the route does not consume becomes the
	 * query string.
	 *
	 * @var array<string, int|string>
	 */
	public array $parameters = [];

	/**
	 * Multipart parts for a POST, when any.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	public ?array $multipart = null;

	/**
	 * The instance-local path the route resolves to.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * Constructor.
	 *
	 * @param string $method GET or POST.
	 * @param string $route  The seam route NAME (one of the gateway's ROUTE_* constants).
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $route,
	) {
	}//end __construct()
}//end class
