<?php

/**
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Signin\BrokerLogin;
use OCA\Portaliq\Service\Signin\SiteReturnAddress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The integriq broker login route beside the OIDC one
 * (signin-integriq-broker-login, design D2). Its own controller rather than
 * two more methods on SessionController, which already sits at the size
 * bound; the posture is the OIDC pair's: public, no CSRF token (a browser
 * navigation), rate-limited per address (ADR-082).
 *
 * Every failure, of either endpoint, lands on the portal with `#signin=failed`
 * and no reason (REQ-BEL-006): the page tells a prober nothing, and a visitor
 * sees one message on the login screen instead of raw JSON.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md
 */
class BrokerSessionController extends Controller {

	/**
	 * The fragment every failed broker login lands on.
	 */
	public const FAILED_FRAGMENT = '#signin=failed';


	/**
	 * Constructor.
	 *
	 * @param IRequest       $request      The request.
	 * @param BrokerLogin    $login        The broker login.
	 * @param IURLGenerator  $urlGenerator Builds the callback and landing addresses.
	 * @param PortalResolver $portals      Resolves the organisation of a `?portal=` slug.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly BrokerLogin $login,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalResolver $portals,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()


	/**
	 * Start a login through integriq: 302 to its start address, or to the
	 * portal's failed-login fragment.
	 *
	 * @param string $org      The organisation slug.
	 * @param string $provider `digid`, `eherkenning` or `eidas`.
	 * @param string $portal   The portal slug the public site sends when it names no organisation.
	 * @param string $returnTo The site page to land on once signed in; only a page on the site route is kept.
	 *
	 * @return RedirectResponse
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 *
	 * @no-admin-idor-exempt The anonymous entry point to a portal's login: a
	 * caller with no session names the organisation and provider it wants.
	 * Nothing of the organisation's configuration reaches the caller; the
	 * answer is a redirect to the configured start address or the same failed
	 * fragment for every refusal, so organisations cannot be enumerated.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function start(string $org = '', string $provider = '', string $portal = '', string $returnTo = ''): RedirectResponse {
		if ($org === '' && $portal !== '') {
			$org = $this->organisationOfPortal(slug: $portal);
		}

		$url = $this->login->start(
			org: $org,
			provider: $provider,
			returnTo: $this->returnPath(returnTo: $returnTo),
			callbackUrl: $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.brokerSession.callback')
		);
		if ($url === null) {
			return $this->failed();
		}

		return new RedirectResponse($url, Http::STATUS_FOUND);
	}//end start()


	/**
	 * Complete a login: 302 to the portal with the bearer in the fragment, or
	 * to the failed-login fragment.
	 *
	 * @param string $state The relay state.
	 * @param string $code  The one-time code.
	 *
	 * @return RedirectResponse
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
	 *
	 * @no-admin-idor-exempt The anonymous return leg of a login. What it acts
	 * on is bound by the single-use state row written at the start and by the
	 * envelope integriq returns for portaliq's own secret; the caller supplies
	 * no object id.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function callback(string $state = '', string $code = ''): RedirectResponse {
		$done = $this->login->complete(state: $state, code: $code);
		if ($done === null) {
			return $this->failed();
		}

		$returnTo = $done['returnTo'];
		if ($returnTo === '') {
			$returnTo = $this->portalPath();
		}

		// The bearer travels in the fragment, never the query: a fragment is
		// not sent to a server and does not reach a log or a Referer header.
		return new RedirectResponse(
			$this->urlGenerator->getAbsoluteURL($returnTo) . '#token=' . rawurlencode($done['token']),
			Http::STATUS_FOUND
		);
	}//end callback()


	/**
	 * The one failed-login answer.
	 *
	 * @return RedirectResponse
	 */
	private function failed(): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->getAbsoluteURL($this->portalPath()) . self::FAILED_FRAGMENT, Http::STATUS_FOUND);
	}//end failed()


	/**
	 * Where a login returns: the site page it was started from, when that is a
	 * page on the site route, else the portal SPA.
	 *
	 * @param string $returnTo The page the site sent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	private function returnPath(string $returnTo): string {
		$site = (new SiteReturnAddress())->accept(
			candidate: $returnTo,
			sitePath: $this->urlGenerator->linkToRoute(Application::APP_ID . '.portalPage.site')
		);
		if ($site !== '') {
			return $site;
		}

		return $this->portalPath();
	}//end returnPath()


	/**
	 * The portal SPA's path.
	 *
	 * @return string
	 */
	private function portalPath(): string {
		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.portalPage.index');
	}//end portalPath()


	/**
	 * The organisation slug of a portal, or '' when it names none.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return string
	 */
	private function organisationOfPortal(string $slug): string {
		$organisation = ($this->portals->resolve(request: $this->request, portalSlug: $slug)['organisation'] ?? null);
		if (is_string($organisation) === false) {
			return '';
		}

		return trim($organisation);
	}//end organisationOfPortal()
}//end class
