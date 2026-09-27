<?php

/**
 * News Guardian Controller
 *
 * The guardian-facing read side of `news-and-newsletter-authoring`: the news
 * feed, one item's read receipt, and the newsletter archive — all scoped to
 * the CALLING guardian's own resolved audience. Guarded by
 * `PortalAuthMiddleware` via the `PortalProtected` marker (fail-closed 401
 * without a valid bearer); the subject is read from the validated bearer,
 * never from a client parameter.
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\NewsFeedReader;
use OCA\Portaliq\Service\NewsReadReceiptService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Guardian read path for news items and the newsletter archive.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */
class NewsGuardianController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param NewsFeedReader $feedReader The guardian-scoped read path.
	 * @param NewsReadReceiptService $readReceipts Idempotent read-receipt recording.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly NewsFeedReader $feedReader,
		private readonly NewsReadReceiptService $readReceipts,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every published news item in the calling guardian's own audience.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function feed(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->feedReader->feedFor(subjectRef: (string)($subject['subjectRef'] ?? '')));
	}//end feed()

	/**
	 * Mark one news item read. Idempotent; 404 for an out-of-audience or
	 * non-existent id (no existence oracle).
	 *
	 * @param string $id The news item id.
	 *
	 * @return JSONResponse 204 on success, 404 otherwise.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function markRead(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$recorded = $this->readReceipts->markRead(subjectRef: (string)($subject['subjectRef'] ?? ''), id: $id);
		if ($recorded === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end markRead()

	/**
	 * Every sent newsletter in the calling guardian's own audience, most
	 * recently sent first.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-composes-existing-news-items-with-an-archive
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function archive(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->feedReader->archiveFor(subjectRef: (string)($subject['subjectRef'] ?? '')));
	}//end archive()

	/**
	 * Resolve the subject from the bearer (fail-closed).
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
