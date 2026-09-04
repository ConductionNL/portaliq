<?php

/**
 * Portaliq Traffic Controller
 *
 * The public collector (portal-traffic-analytics): the batch endpoint, the
 * no-script pixel and the served client.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\RawRequestBody;
use OCA\Portaliq\Service\TrafficEventValidator;
use OCA\Portaliq\Service\TrafficIngestService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Throwable;

/**
 * Anonymous, unauthenticated, writable by anyone who can reach the portal:
 * the same posture as the portal itself, and the reason every input here is
 * bounded before it is looked at.
 *
 * THE PORTAL IS RESOLVED BY HOST FIRST. The content API resolves an explicit
 * slug first because a Docusaurus build reaching Portaliq over the platform
 * host has to name its site. The collector reverses that: a caller must not
 * be able to attribute events to a portal it merely names. The slug is
 * accepted only when the host resolves to nothing, which is the case for an
 * external portal on its own domain.
 *
 * Rate limits carry BOTH attributes. An `AnonRateLimit` alone caps
 * authenticated callers at Nextcloud's default, which is far lower, so an
 * editor previewing their own site would be throttled before a visitor.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
 */
class TrafficController extends Controller {

	/**
	 * The most bytes a batch may carry: 64 KB.
	 */
	public const MAX_BODY_BYTES = 65536;

	/**
	 * A 1x1 transparent GIF, the smallest image a mail client will fetch.
	 */
	private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/**
	 * Constructor.
	 *
	 * @param string               $appName    The app id.
	 * @param IRequest             $request    The request.
	 * @param PortalResolver       $resolver   Resolves the serving portal.
	 * @param TrafficIngestService $ingest     Validates and stores events.
	 * @param RawRequestBody       $body       Reads the text body.
	 * @param IAppManager          $appManager Locates the built client on disk.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly TrafficIngestService $ingest,
		private readonly RawRequestBody $body,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * Accept a batch of events.
	 *
	 * A batch that is too large, malformed, empty or over fifty events is
	 * refused WHOLE with a 400 and nothing stored. Anything else is 204 with
	 * no body: per-event refusals are counted on the metrics endpoint, not
	 * reported to the client, which has no use for them and no business
	 * learning which portals exist.
	 *
	 * @return Response 204, or a 400 with a reason.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	public function collect(): Response {
		$raw = $this->body->read(max: self::MAX_BODY_BYTES);
		if ($raw === null) {
			return $this->refusal(reason: 'batch-too-large');
		}

		$batch = json_decode($raw, true);
		if (is_array($batch) === false || is_array($batch['events'] ?? null) === false || $batch['events'] === []) {
			return $this->refusal(reason: 'malformed-batch');
		}

		if (count($batch['events']) > TrafficEventValidator::MAX_BATCH) {
			return $this->refusal(reason: 'batch-too-large');
		}

		$slug = $batch['portal'] ?? null;
		if (is_string($slug) === false) {
			$slug = null;
		}

		$this->ingestFor(slug: $slug, events: array_values($batch['events']), consent: (($batch['consent'] ?? false) === true));

		return $this->noContent();
	}//end collect()


	/**
	 * The no-script fallback: one event per image request.
	 *
	 * @param string|null $portal The portal slug, for an external site.
	 * @param string|null $e      The event name; page_view when absent.
	 * @param string|null $l      The page location; the referer when absent.
	 *
	 * @return DataDisplayResponse A 1x1 GIF, always.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	public function pixel(?string $portal = null, ?string $e = null, ?string $l = null): DataDisplayResponse {
		$location = $l;
		if (is_string($location) === false || trim($location) === '') {
			$location = $this->request->getHeader('Referer');
		}

		$name = $e;
		if ($name === null || $name === '') {
			$name = 'page_view';
		}

		$event = [
			'name' => $name,
			'sequence' => 0,
			'pageLocation' => $location,
		];
		$this->ingestFor(slug: $portal, events: [$event], consent: false);

		$response = new DataDisplayResponse(
			(string)base64_decode(self::PIXEL, true),
			Http::STATUS_OK,
			['Content-Type' => 'image/gif']
		);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end pixel()


	/**
	 * The traffic client, served from the app directory.
	 *
	 * A ROUTE, NOT A FILE PATH. An anonymous request for the file under
	 * `js/` answers 401 on a Nextcloud host, which is every request a public
	 * portal makes. The plugin that ships the tag already points here.
	 *
	 * @return DataDisplayResponse The script, a 304, or a 404.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-served-client-must-be-one-file-for-every-renderer
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function client(): DataDisplayResponse {
		$path = $this->clientPath();
		if ($path === null) {
			$missing = new DataDisplayResponse('', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
			$missing->addHeader('Cache-Control', 'private, no-store');

			return $missing;
		}

		$etag = '"' . (string)filemtime($path) . '-' . (string)filesize($path) . '"';
		$headers = [
			'Content-Type' => 'application/javascript; charset=utf-8',
			'Cache-Control' => 'public, max-age=3600',
			'ETag' => $etag,
		];
		if (trim($this->request->getHeader('If-None-Match')) === $etag) {
			return new DataDisplayResponse('', Http::STATUS_NOT_MODIFIED, $headers);
		}

		return new DataDisplayResponse((string)file_get_contents($path), Http::STATUS_OK, $headers);
	}//end client()


	/**
	 * Where the built client lives, or null when it was never built.
	 *
	 * @return string|null The absolute path.
	 */
	private function clientPath(): ?string {
		try {
			$path = $this->appManager->getAppPath(Application::APP_ID) . '/js/portaliq-traffic.js';
		} catch (Throwable) {
			return null;
		}

		if (is_file($path) === false) {
			return null;
		}

		return $path;
	}//end clientPath()


	/**
	 * Resolve the portal (host first, slug second) and hand the batch to the
	 * ingest service, which counts whatever it refuses.
	 *
	 * @param string|null              $slug    The slug the caller named, if any.
	 * @param array<int, mixed>        $events  The batch.
	 * @param bool                     $consent The consent state the client reported.
	 *
	 * @return void
	 */
	private function ingestFor(?string $slug, array $events, bool $consent): void {
		$context = [
			'ip' => $this->request->getRemoteAddress(),
			'userAgent' => $this->request->getHeader('User-Agent'),
			'acceptLanguage' => $this->request->getHeader('Accept-Language'),
			'consent' => $consent,
			'serverSide' => false,
		];

		$portal = $this->resolver->resolveForCollector(request: $this->request, portalSlug: $slug);
		if ($portal !== null) {
			$this->ingest->ingestForPortal(portal: $portal, events: $events, context: $context);

			return;
		}

		// Nothing resolved. The ingest service refuses the batch under
		// `unknown-portal` and counts it, so a misconfigured tag is visible.
		$this->ingest->ingest(portalSlug: (string)($slug ?? ''), events: $events, context: $context);
	}//end ingestFor()


	/**
	 * The 204 every accepted batch gets.
	 *
	 * @return Response No content, not cacheable.
	 */
	private function noContent(): Response {
		$response = new Response(Http::STATUS_NO_CONTENT);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end noContent()


	/**
	 * A whole-batch refusal, with its reason.
	 *
	 * @param string $reason The reason.
	 *
	 * @return JSONResponse A 400.
	 */
	private function refusal(string $reason): JSONResponse {
		$response = new JSONResponse(['error' => $reason], Http::STATUS_BAD_REQUEST);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end refusal()
}//end class
