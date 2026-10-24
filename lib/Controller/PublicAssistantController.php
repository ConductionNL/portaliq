<?php

/**
 * Portaliq Public Assistant Controller
 *
 * The anonymous "ask a question" route of the public assistant widget.
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
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Assistant\PublicAssistantChannel;
use OCA\Portaliq\Service\Assistant\PublicSourceScope;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers a visitor's question from the portal's published content.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t04
 */
class PublicAssistantController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalResolver $portals Resolves the portal being visited.
	 * @param PublicSourceScope $scope Whether the portal turned the assistant on.
	 * @param PublicAssistantChannel $channel The adapter to hermiq.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PublicSourceScope $scope,
		private readonly PublicAssistantChannel $channel,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Ask the assistant one question.
	 *
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 * @param string $question The question.
	 * @param string $locale The visitor's locale.
	 * @param string $conversationId A conversation id issued earlier in this visit.
	 *
	 * @return JSONResponse `{status, answer, sources, removed, conversationId}`; 400 on a bearer.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t04
	 *
	 * @no-admin-idor-exempt Anonymous by design: it reads only the portal's published
	 * pages, glossary and publications, forwards no identity, and is rate limited per client.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function ask(string $portal='', string $question='', string $locale='nl', string $conversationId=''): JSONResponse {
		// A signed-in resident's identity must not reach the assistant by accident.
		if (trim($this->request->getHeader('Authorization')) !== '') {
			return new JSONResponse(['error' => 'bearer_not_accepted'], Http::STATUS_BAD_REQUEST);
		}

		$slug = null;
		if ($portal !== '') {
			$slug = $portal;
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: $slug);
		if ($site === null || $this->scope->enabledFor(portal: $site) === false) {
			return new JSONResponse(['error' => 'assistant_off'], Http::STATUS_NOT_FOUND);
		}

		if (trim($question) === '') {
			return new JSONResponse(['error' => 'question_required'], Http::STATUS_BAD_REQUEST);
		}

		$answer = $this->channel->ask(portal: $site, question: $question, locale: $locale, conversationId: $conversationId);
		if ($answer['status'] === 'unavailable') {
			return new JSONResponse(['error' => 'assistant_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($answer);
	}//end ask()
}//end class
