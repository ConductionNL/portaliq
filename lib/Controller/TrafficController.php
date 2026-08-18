<?php

/**
 * Portaliq Traffic Collector
 *
 * Accepts client-reported interaction events from a public portal.
 *
 * MEASUREMENT IS CLIENT-REPORTED because nothing else can answer the question:
 * `portalSession` exists only for authenticated visitors and carries no page,
 * OpenRegister's read log is an AVG verwerkingsregister with no visit or
 * ordering, and a Docusaurus-rendered portal is static HTML served elsewhere
 * with portaliq nowhere in the request path.
 *
 * NO IP IS EVER STORED: the request address is resolved to a coarse region and
 * dropped inside `collect()`, before the service sees anything.
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
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalTrafficReporter;
use OCA\Portaliq\Service\PortalTrafficService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\App\IAppManager;
use OCP\IRequest;

/**
 * The traffic collector.
 *
 * WHY MEASUREMENT IS CLIENT-REPORTED HERE, recorded so nobody re-derives it:
 * `portalSession` exists only for authenticated visitors and carries no page;
 * OpenRegister's read log is an AVG verwerkingsregister with no visit, referrer
 * or ordering, and pointing analytics at it would make an accountability record
 * carry traffic; and a Docusaurus-rendered portal is static HTML served
 * elsewhere, with portaliq nowhere in the request path. There is nothing on the
 * server to count.
 *
 * WHAT THIS ENDPOINT WILL NOT DO. It stores no IP address: the request IP is
 * resolved to a coarse region and dropped inside `collect()`, never handed to
 * the service, never logged. It sets no cookie and returns no body, so it cannot
 * be used to identify anyone by its response either.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class TrafficController extends Controller {

	/**
	 * A batch larger than this is refused WHOLE.
	 *
	 * Refusing the whole batch rather than the excess is deliberate: a partial
	 * store leaves a journey with holes that read as a visitor who left, which
	 * is worse than a journey that is visibly absent.
	 */
	private const MAX_BATCH = 50;

	/**
	 * @param string               $appName  The app id.
	 * @param IRequest             $request  The request.
	 * @param PortalResolver       $resolver Resolves the serving portal.
	 * @param PortalTrafficService $traffic  Validates and stores events.
	 * @param IAppManager|null     $appManager Locates the built client bundle.
	 * @param PortalTrafficReporter|null $reporter Reads traffic back for an operator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly PortalTrafficService $traffic,
		private readonly ?IAppManager $appManager = null,
		private readonly ?PortalTrafficReporter $reporter = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * Accept a batch of client-reported events.
	 *
	 * ALWAYS 204, AND ALWAYS WITHOUT A BODY. A beacon fired from
	 * `navigator.sendBeacon` during page unload cannot read a response, retry,
	 * or report an error to anyone — so a status a client cannot act on is
	 * noise, and a body it never reads is a way to leak whether a portal exists.
	 * Refusals are COUNTED rather than returned, which is what makes them
	 * visible to the portal's own operator instead of to the caller.
	 *
	 * The portal is resolved the way the renderer resolves it — by host, then
	 * by explicit slug — and never taken from the payload. Otherwise any caller
	 * could attribute traffic to a portal it does not serve.
	 *
	 * @param array|null $events The batch.
	 * @param string     $portal An explicit portal slug, for a client not on the portal's own host.
	 *
	 * @return DataResponse Always 204.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function collect(?array $events = null, string $portal = ''): DataResponse {
		$empty = $this->cors(response: new DataResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));

		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $empty;
		}

		if (is_array($events) === false || $events === [] || count($events) > self::MAX_BATCH) {
			$this->traffic->countRefusal(reason: 'batch');
			return $empty;
		}

		// THE IP IS RESOLVED AND DROPPED HERE, in this method, before anything
		// else sees the request. The service takes a region string and has no
		// way to ask for an address.
		$region = $this->traffic->regionFor(
			address: (string)$this->request->getRemoteAddress(),
			portal: $resolved
		);

		$this->traffic->record(portal: $resolved, events: $events, region: $region);

		return $empty;
	}//end collect()


	/**
	 * Answer the browser's preflight for a cross-origin beacon.
	 *
	 * WITHOUT THIS, A STATICALLY BUILT PORTAL CANNOT REPORT ANYTHING. A beacon
	 * carrying `application/json` is not a simple request, so the browser asks
	 * first; an unanswered preflight fails the send silently, on the visitor's
	 * side, where nobody operating the portal can see it. Measured before it
	 * was written: the collector answered 204 to `curl` and had no
	 * `Access-Control-Allow-Origin` at all.
	 *
	 * A CEILING EVEN ON A PREFLIGHT (ADR-082). It is cheap to answer and cheaper
	 * to send, which is exactly the shape a public endpoint gets hammered on;
	 * generous enough that a page making one preflight per beacon never
	 * notices.
	 *
	 * @return DataResponse An empty 204 carrying the CORS headers.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflight(): DataResponse {
		return $this->cors(response: new DataResponse(data: [], statusCode: Http::STATUS_NO_CONTENT));
	}//end preflight()


	/**
	 * Serve the traffic client to a portal this app does not render.
	 *
	 * A ROUTE, NOT A FILE PATH, and the difference is not cosmetic. The obvious
	 * URL — `/index.php/apps/portaliq/js/portaliq-traffic.js` — answers **401**
	 * to an anonymous caller on this instance, which is every caller this
	 * script exists for; only the deployment-dependent `/custom_apps/…` path
	 * serves it, and that path is not the same on every instance. A declared
	 * public route is stable and framework-controlled, so the URL a built site
	 * bakes in keeps working.
	 *
	 * Cached for an hour rather than immutably: the client is the one file a
	 * portal cannot rebuild its visitors' copies of, and a privacy fix has to
	 * reach them without anyone rebuilding a static site.
	 *
	 * The script is cached for an hour by the response below, so a real visitor
	 * fetches it once a session; the ceiling below bounds a caller that ignores
	 * the cache rather than one that uses it (ADR-082).
	 *
	 * @return Response The script, or 404 when the bundle was never built.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function client(): Response {
		$path = '';
		if ($this->appManager !== null) {
			$path = ($this->appManager->getAppPath('portaliq') . '/js/portaliq-traffic.js');
		}

		if ($path === '' || file_exists($path) === false) {
			return new DataResponse(data: [], statusCode: Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse(
			(string)file_get_contents($path),
			Http::STATUS_OK,
			['Content-Type' => 'application/javascript; charset=utf-8']
		);
		$response->addHeader('Cache-Control', 'public, max-age=3600, must-revalidate');

		return $this->cors(response: $response);
	}//end client()


	/**
	 * What a portal's traffic looks like, for whoever operates it.
	 *
	 * NOT PUBLIC, AND NOT CORS-ENABLED. Everything else on this controller is
	 * anonymous because a visitor's browser has to reach it; this is the one
	 * that reads BACK, and an aggregate of where a portal's visitors go is a
	 * portal operator's business rather than the open web's.
	 *
	 * ADMIN-ONLY, AND THE FIRST VERSION WAS NOT. It carried
	 * `#[NoAdminRequired]` on the reasoning that the people who run a portal's
	 * content are not always instance administrators — which is true, and
	 * beside the point: this method takes a portal SLUG and returns that
	 * portal's traffic, so with only "is anyone logged in" between them, any
	 * authenticated user could read any tenant's figures by naming it. Caught
	 * by gate-7 (no-admin-idor).
	 *
	 * Scoping to the caller's organisation would be the better answer and this
	 * app has no mechanism for it: `AdminSettings` is deliberately a plain
	 * `ISettings` rather than `IDelegatedSettings`, so there is no delegated
	 * portal-operator role to scope BY. Inventing one here would be asserting a
	 * boundary that does not exist. Carrying NO auth attribute is Nextcloud's
	 * "instance admin + CSRF" default — the same posture, and the same
	 * reasoning, as `SessionAdminController`. When delegated administration
	 * arrives, this is one of the endpoints that should take it.
	 *
	 * The `measured` flag is produced by the service, not by this method, so a
	 * renderer cannot be handed zeroes for a portal that measures nothing.
	 *
	 * @param string $portal An explicit portal slug.
	 *
	 * @return DataResponse The summary, or a 404 when no portal resolves.
	 *
	 * @auth admin-only Reads one portal's traffic aggregate by SLUG, so the
	 *       guard cannot be "is anyone logged in": with only that, any
	 *       authenticated user could read any tenant's figures by naming it.
	 *       Carries no auth attribute deliberately — Nextcloud's default is
	 *       instance admin + CSRF, the same posture as SessionAdminController.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function summary(string $portal = ''): DataResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return new DataResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($this->reporter === null) {
			return new DataResponse(data: ['measured' => false]);
		}

		return new DataResponse(data: $this->reporter->summaryFor(portal: $resolved));
	}//end summary()


	/**
	 * Allow any origin to reach this endpoint, without credentials.
	 *
	 * `*` IS CORRECT HERE AND CREDENTIALS ARE NOT ALLOWED. The collector is
	 * anonymous, sets no cookie and returns no body — there is nothing an
	 * origin could read that it could not read by making the request itself.
	 * Naming allowed origins instead would mean maintaining a list of every
	 * host a portal is published on, and a portal that quietly stops being
	 * measured after a domain change is worse than no list.
	 *
	 * @param T $response The response to decorate.
	 *
	 * @return T The same response, so a caller keeps the type it passed in.
	 *
	 * @template T of Response
	 */
	private function cors(Response $response): Response {
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->addHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
		$response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
		$response->addHeader('Access-Control-Max-Age', '3600');

		return $response;
	}//end cors()


}//end class
