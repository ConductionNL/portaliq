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
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Traffic\RawRequestBody;
use OCA\Portaliq\Service\Traffic\TrafficRecordingService;
use OCA\Portaliq\Service\Traffic\TrafficServerBatch;
use OCA\Portaliq\Service\Traffic\TrafficServerToken;
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the edge of the
 * collector meets the resolver, the ingest, the body reader, the app
 * manager, the session service, the token verifier, the recording
 * service and six response and attribute classes; the ones over the
 * threshold are what account linking (portal-traffic-visitors-and-geo),
 * the server API (portal-traffic-reporting) and session recording
 * (portal-traffic-experiments) each need.
 */
class TrafficController extends Controller {

	/**
	 * The most bytes a batch may carry: 64 KB.
	 */
	public const MAX_BODY_BYTES = 65536;

	/**
	 * The most bytes a recording chunk may carry on the wire: the 256 KB
	 * the service keeps plus room for the envelope around it.
	 */
	public const MAX_RECORDING_BODY_BYTES = 294912;

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
	 * @param PortalSessionService $session    Resolves a portal session bearer to its subject, for account linking.
	 * @param TrafficServerToken   $tokens     Verifies a backend's bearer token (portal-traffic-reporting).
	 * @param TrafficRecordingService $recordings Stores a session recording chunk (portal-traffic-experiments).
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
		private readonly PortalSessionService $session,
		private readonly TrafficServerToken $tokens,
		private readonly TrafficRecordingService $recordings,
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
		$batch = $this->decoded(max: self::MAX_BODY_BYTES, bounded: true);
		if ($batch instanceof JSONResponse) {
			return $batch;
		}

		$this->ingestFor(slug: $this->slugOf(batch: $batch), events: array_values($batch['events']), consent: (($batch['consent'] ?? false) === true));

		return $this->noContent();
	}//end collect()


	/**
	 * The request body as a decoded batch, or the 400 that refuses it
	 * whole: over the cap, malformed, without events, or (when bounded)
	 * over fifty events.
	 *
	 * @param int  $max     The most bytes accepted.
	 * @param bool $bounded Whether the event count is capped at MAX_BATCH.
	 *
	 * @return array<string, mixed>|JSONResponse The batch, or the refusal.
	 */
	private function decoded(int $max, bool $bounded): array|JSONResponse {
		$raw = $this->body->read(max: $max);
		if ($raw === null) {
			return $this->refusal(reason: 'batch-too-large');
		}

		$batch = json_decode($raw, true);
		if (is_array($batch) === false || is_array($batch['events'] ?? null) === false || $batch['events'] === []) {
			return $this->refusal(reason: 'malformed-batch');
		}

		if ($bounded === true && count($batch['events']) > TrafficEventValidator::MAX_BATCH) {
			return $this->refusal(reason: 'batch-too-large');
		}

		return $batch;
	}//end decoded()


	/**
	 * The portal slug a batch names, or null.
	 *
	 * @param array<string, mixed> $batch The decoded batch.
	 *
	 * @return string|null The slug.
	 */
	private function slugOf(array $batch): ?string {
		$slug = $batch['portal'] ?? null;
		if (is_string($slug) === false) {
			return null;
		}

		return $slug;
	}//end slugOf()


	/**
	 * Accept a batch from a trusted backend, on a visitor's behalf.
	 *
	 * The same envelope as `collect()`, plus `remoteAddress` and
	 * `userAgent` on the batch or on each event, so a server that saw the
	 * visitor can say what its browser would have. The portal is named in
	 * the body and the bearer token must be that portal's: a wrong or
	 * missing token is a 401 with nothing stored and nothing learned
	 * about which portals exist. The address and agent go the way a live
	 * request's do: read for the hash, the device family and the region,
	 * then dropped.
	 *
	 * @return Response 204, a 400 with a reason, or a 401.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	public function server(): Response {
		$batch = $this->decoded(max: self::MAX_BODY_BYTES, bounded: true);
		if ($batch instanceof JSONResponse) {
			return $batch;
		}

		$portal = $this->portalBySlug(slug: $batch['portal'] ?? null);
		if ($portal === null || $this->tokens->verify(portal: $portal, token: $this->bearer()) === false) {
			return $this->unauthorised();
		}

		$consent = (($batch['consent'] ?? false) === true);
		// Pure and stateless, so built here rather than injected: a tenth
		// constructor argument for a regrouping is more ceremony than fact.
		$batches = new TrafficServerBatch();
		foreach ($batches->byVisitor(batch: $batch) as $group) {
			$this->ingest->ingestForPortal(
				portal: $portal,
				events: $group['events'],
				context: [
					'ip' => $group['ip'],
					'userAgent' => $group['userAgent'],
					'acceptLanguage' => (string)($batch['acceptLanguage'] ?? ''),
					'consent' => $consent,
					'serverSide' => false,
				]
			);
		}

		return $this->noContent();
	}//end server()


	/**
	 * The published portal named in the batch, or null.
	 *
	 * @param mixed $slug The `portal` field.
	 *
	 * @return array<string, mixed>|null The portal record.
	 */
	private function portalBySlug(mixed $slug): ?array {
		if (is_string($slug) === false || $slug === '') {
			return null;
		}

		foreach ($this->resolver->allPublishedPortals() as $portal) {
			if (($portal['slug'] ?? null) === $slug) {
				return $portal;
			}
		}

		return null;
	}//end portalBySlug()


	/**
	 * The bearer token on the request, or ''.
	 *
	 * @return string The token.
	 */
	private function bearer(): string {
		$header = trim($this->request->getHeader('Authorization'));
		if (stripos($header, 'Bearer ') !== 0) {
			return '';
		}

		return trim(substr($header, 7));
	}//end bearer()


	/**
	 * A 401 for a missing or wrong token.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function unauthorised(): JSONResponse {
		$this->stateless();
		$response = new JSONResponse(['error' => 'invalid-token'], Http::STATUS_UNAUTHORIZED);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end unauthorised()


	/**
	 * Accept one chunk of a session recording (portal-traffic-experiments).
	 *
	 * The same posture as `collect()`: public, text/plain, the portal by
	 * host first, 204 with nothing said about why a chunk was not kept.
	 * The service refuses a chunk for a portal whose operator did not
	 * switch recording on, for an external portal, before consent where
	 * consent is required, and past the per-chunk and per-visit budgets;
	 * every refusal is counted on the metrics endpoint.
	 *
	 * @return Response 204, or a 400 with a reason.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	public function recording(): Response {
		$chunk = $this->decoded(max: self::MAX_RECORDING_BODY_BYTES, bounded: false);
		if ($chunk instanceof JSONResponse) {
			return $chunk;
		}

		$portal = $this->resolver->resolveForCollector(request: $this->request, portalSlug: $this->slugOf(batch: $chunk));
		if ($portal !== null) {
			$this->recordings->ingest(portal: $portal, body: $chunk, context: ['consent' => (($chunk['consent'] ?? false) === true)]);
		}

		return $this->noContent();
	}//end recording()


	/**
	 * The session recorder, served like the client and for the same
	 * reason: it is public code, loaded by the client only for a portal
	 * whose operator switched recording on, and a request for it proves
	 * nothing about any portal.
	 *
	 * @return DataDisplayResponse The script, a 304, or a 404.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function recorder(): DataDisplayResponse {
		return $this->script(file: 'portaliq-traffic-recorder.js');
	}//end recorder()


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

		$this->stateless();
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
		return $this->script(file: 'portaliq-traffic.js');
	}//end client()


	/**
	 * One built script from the app's `js/` directory, with an ETag so a
	 * returning browser fetches it once an hour and revalidates after.
	 *
	 * @param string $file The built file's name.
	 *
	 * @return DataDisplayResponse The script, a 304, or a 404.
	 */
	private function script(string $file): DataDisplayResponse {
		$this->stateless();
		$path = $this->clientPath(file: $file);
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
	}//end script()


	/**
	 * Where a built script lives, or null when it was never built.
	 *
	 * @param string $file The built file's name.
	 *
	 * @return string|null The absolute path.
	 */
	private function clientPath(string $file): ?string {
		try {
			$path = $this->appManager->getAppPath(Application::APP_ID) . '/js/' . $file;
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
			'userRef' => $this->subjectRef(),
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
	 * The portal account's pseudonymous reference, when the batch carries
	 * a valid portal session bearer; '' otherwise.
	 *
	 * Resolved here, at the edge, and handed to the ingest service as one
	 * more piece of request context. The ingest service keeps it only for
	 * a portal that switched on account linking; a bearer on a batch for
	 * any other portal is read and forgotten like the address is.
	 *
	 * @return string The subjectRef, or ''.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-account-linking-must-attach-only-a-pseudonymous-reference
	 */
	private function subjectRef(): string {
		$header = trim($this->request->getHeader('Authorization'));
		if ($header === '') {
			return '';
		}

		$subject = $this->session->resolveFromBearer($header);

		return trim((string)($subject['subjectRef'] ?? ''));
	}//end subjectRef()


	/**
	 * The 204 every accepted batch gets.
	 *
	 * @return Response No content, not cacheable.
	 */
	private function noContent(): Response {
		$this->stateless();
		$response = new Response(Http::STATUS_NO_CONTENT);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end noContent()


	/**
	 * Drop every cookie the platform queued for this response.
	 *
	 * Nextcloud opens a PHP session and sets its same-site guard cookies
	 * before any controller runs, on every request, public ones included.
	 * The collector needs none of that: it identifies nobody by cookie and
	 * answers the same to everyone. Measured on the throwaway instance: the
	 * first anonymous POST came back with the session cookie, which is
	 * exactly the "no Set-Cookie" the contract promises a visitor. The
	 * headers live on PHP's own list, not on the Response, so they are
	 * removed there; a session that was never sent is a session that
	 * cannot be resumed, and nothing here wants one.
	 *
	 * @return void
	 */
	private function stateless(): void {
		if (headers_sent() === false) {
			header_remove('Set-Cookie');
		}
	}//end stateless()


	/**
	 * A whole-batch refusal, with its reason.
	 *
	 * @param string $reason The reason.
	 *
	 * @return JSONResponse A 400.
	 */
	private function refusal(string $reason): JSONResponse {
		$this->stateless();
		$response = new JSONResponse(['error' => $reason], Http::STATUS_BAD_REQUEST);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end refusal()
}//end class
