<?php

/**
 * Public API CORS Middleware
 *
 * Reflects the request Origin onto responses from `#[PublicPage]` endpoints
 * under `/api/content` and `/api/traffic`, so a browser on a different origin
 * (a Docusaurus build on its own domain, an external portal's pages) can read
 * a content response and post a traffic batch. Scoped strictly to public
 * endpoints: a public page carries no credentials, so echoing the Origin
 * without `Access-Control-Allow-Credentials` is safe and cannot be leveraged
 * for a credentialed cross-site read.
 *
 * Only "simple" cross-origin requests are supported (no preflight): a caller
 * posts `text/plain` so the browser skips the OPTIONS the app router has no
 * route for. An `application/json` body would trigger a preflight and fail,
 * which is why the collector's contract says text/plain.
 *
 * Copied from OpenRegister's middleware of the same name, narrowed to the
 * two path families this app exposes publicly.
 *
 * @category Middleware
 * @package  OCA\Portaliq\Middleware
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-cross-origin-posting-must-work-without-a-preflight
 */

declare(strict_types=1);

namespace OCA\Portaliq\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use Throwable;

/**
 * Reflect-Origin CORS headers on the public content and traffic responses.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-cross-origin-posting-must-work-without-a-preflight
 */
class PublicApiCorsMiddleware extends Middleware {

	/**
	 * The path families that get the headers. Anchored on the app's API
	 * prefix so an unrelated public route in this app is not opened by
	 * accident.
	 */
	private const PATHS = '#/api/(content|traffic)(/|$|-client\.js$|-recorder\.js$)#';

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request   The current request.
	 * @param IControllerMethodReflector $reflector Reads the dispatched method's attributes.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IControllerMethodReflector $reflector,
	) {
	}//end __construct()


	/**
	 * Reflect the Origin on a public content or traffic response.
	 *
	 * Fails open: any error leaves the response unchanged (no CORS header) so
	 * a bug here can never turn a working same-origin call into a 500.
	 *
	 * @param Controller $controller The controller being dispatched (unused: the reflector already points at it).
	 * @param string     $methodName The method being dispatched (unused, see above).
	 * @param Response   $response   The outgoing response.
	 *
	 * @return Response The response, with reflect-Origin headers when it qualifies.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-cross-origin-posting-must-work-without-a-preflight
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; the reflector already knows the dispatched method.
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		try {
			$origin = trim($this->request->getHeader('Origin'));
			if ($origin === '' || preg_match('#^https?://[^/\s]+$#i', $origin) !== 1) {
				return $response;
			}

			if (preg_match(self::PATHS, (string)$this->request->getPathInfo()) !== 1) {
				return $response;
			}

			// The reflector was pointed at the dispatched method by the
			// framework before any middleware ran; the OCP interface exposes
			// no `reflect()` to point it again. (Calling one anyway threw,
			// the catch below swallowed it, and no response was ever opened:
			// the tests caught what a browser would only have shown as a
			// silent CORS failure).
			if ($this->reflector->hasAnnotationOrAttribute('PublicPage', PublicPage::class) === false) {
				return $response;
			}

			// Never combine a reflected Origin with credentials: that pairing
			// is the cross-site read the platform guards against elsewhere.
			$response->addHeader('Access-Control-Allow-Origin', $origin);
			$response->addHeader('Access-Control-Allow-Methods', 'GET, POST');
			$response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
			$response->addHeader('Vary', 'Origin');
		} catch (Throwable) {
			return $response;
		}//end try

		return $response;
	}//end afterController()
}//end class
