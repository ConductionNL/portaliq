<?php

/**
 * Portaliq search suggestion controller
 *
 * The did-you-mean route the public search block asks when a search with a
 * term found fewer than three results. Public, because the visitor is
 * anonymous; rate limited, because it runs a search per call.
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
 * @version GIT: <git-id>
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Answers a checked spelling suggestion for a public search term.
 */
class SearchSuggestController extends Controller {

	/**
	 * Longer terms are not corrected; a visitor who typed a sentence is not
	 * helped by a word-by-word rewrite.
	 */
	private const MAX_TERM_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request   The request.
	 * @param PortalResolver    $portals   Resolves the serving portal.
	 * @param SpellingSuggester $suggester Picks the checked correction.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly SpellingSuggester $suggester,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/site/search/suggest?portal={slug}&q={term}`.
	 *
	 * Answers `{suggestion: string|null, results: int}`. An unknown portal is
	 * a 404 with the same shape and no suggestion.
	 *
	 * @param string $portal The portal slug; the host decides when empty.
	 * @param string $q      The term the visitor searched for.
	 *
	 * @return JSONResponse The suggestion and how many results it finds.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function suggest(string $portal='', string $q=''): JSONResponse {
		$none = ['suggestion' => null, 'results' => 0];

		$slug = null;
		if ($portal !== '') {
			$slug = $portal;
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: $slug);
		if ($site === null || is_string($site['slug'] ?? null) === false) {
			return new JSONResponse($none, Http::STATUS_NOT_FOUND);
		}

		$term = trim($q);
		if ($term === '' || mb_strlen($term) > self::MAX_TERM_LENGTH) {
			return new JSONResponse($none);
		}

		try {
			return new JSONResponse($this->suggester->suggest(portal: $site['slug'], term: $term));
		} catch (Throwable) {
			return new JSONResponse($none);
		}
	}//end suggest()
}//end class
