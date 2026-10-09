<?php

/**
 * The "Bedoelde u" correction behind the public search block.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers `{suggestion, results}` for a term that found little.
 *
 * Public, because the search is public; rate limited, because each answer
 * runs one search. The portal must be a published one this request reaches.
 * The suggestion comes only from the portal's word list of public titles and
 * summaries, so the route reveals nothing the public search does not.
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SearchSuggestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request   The request.
	 * @param PortalResolver    $portals   Resolves the serving portal.
	 * @param SpellingSuggester $suggester Picks and checks the correction.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly SpellingSuggester $suggester,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The correction of a term, or `suggestion: null`.
	 *
	 * The term arrives as `q`, the name the search block and the public
	 * search share; it is read from the request rather than bound, because a
	 * one-letter argument name is what phpmd forbids.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function suggest(string $portal=''): JSONResponse {
		$named = null;
		if ($portal !== '') {
			$named = $portal;
		}

		$resolved = $this->portals->resolve(request: $this->request, portalSlug: $named);
		$slug     = (string)($resolved['slug'] ?? '');
		if ($slug === '') {
			return new JSONResponse(['suggestion' => null, 'results' => 0]);
		}

		return new JSONResponse($this->suggester->suggest(portal: $slug, term: (string)$this->request->getParam('q', '')));
	}//end suggest()
}//end class
