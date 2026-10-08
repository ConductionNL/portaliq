<?php

/**
 * Portaliq Example Resident Controller
 *
 * One click on a demo: the example resident's portal session
 * (example-resident-demo-login). The route lives beside the other sign-in
 * routes under `/portal/api/session/`, but it is a demo door, not part of the
 * auth edge a production portal offers, so it has its own controller.
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
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentSignIn;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The one-click demo sign-in for the example resident.
 *
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */
class ExampleResidentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request      The request object.
	 * @param PortalSessionService  $session      Mints the portal session.
	 * @param PortalAccountService  $accounts     Finds the resident's `portalAccount`.
	 * @param IURLGenerator         $urlGenerator Builds the final SPA redirect.
	 * @param PortalResolver        $portals      Resolves the portal being signed in to.
	 * @param ExampleResidentSignIn $signIns      The demo switch and the installed resident of a portal.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalAccountService $accounts,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalResolver $portals,
		private readonly ExampleResidentSignIn $signIns,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One click on a demo: the example resident's session
	 * (example-resident-demo-login).
	 *
	 * The example resident's way in is the `nextcloud` mode, which exchanges
	 * a Nextcloud session for a portal session; a demo visitor got the
	 * platform's login form. This route mints the same session without the
	 * form, for the example resident's OWN account id only. It is closed
	 * unless an administrator set `example_resident_demo_login` to `yes`;
	 * `debug` mode does NOT open it, unlike the test sign-in. The subject is
	 * the install record's, never the caller's, and every refusal is a
	 * throttled 404, so the route is no oracle for which residents exist.
	 *
	 * @param string $id       The example resident's id (`zuiddrecht`).
	 * @param string $portal   The portal slug to sign in to.
	 * @param string $returnTo Where to send the browser afterwards.
	 *
	 * @return Response A redirect carrying the bearer in the URL fragment, or 404.
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[BruteForceProtection(action: 'portaliq_example_resident_login')]
	public function signIn(string $id = '', string $portal = '', string $returnTo = ''): Response {
		$userId  = $this->residentUser(id: $id, portal: $portal);
		$account = null;
		if ($userId !== '') {
			$account = $this->accounts->findBySubjectRef(subjectRef: $userId);
		}

		if ($account === null || ($account['status'] ?? 'active') !== 'active') {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['reason' => 'example_resident_login_closed']);
			return $response;
		}

		$audience = (string)($account['audience'] ?? 'client');
		$issued   = $this->session->issueSession(
			subjectRef: $userId,
			audience: $audience,
			organisation: (string)($account['organisation'] ?? 'dev-org'),
			// No password was asked: the lowest assurance, as dev-login and
			// the nextcloud mode carry.
			trust: 'low',
			roles: [$audience . ':read']
		);
		if ($issued === null) {
			return new JSONResponse(['error' => 'not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$target = $returnTo;
		if ($target === '') {
			$target = '/apps/portaliq/site?portal=' . rawurlencode(string: $portal);
		}

		return new RedirectResponse(
			$this->urlGenerator->getAbsoluteURL($target) . '#token=' . rawurlencode(string: $issued['token']),
			Http::STATUS_FOUND
		);
	}//end signIn()

	/**
	 * The Nextcloud account id the one-click demo sign-in may mint for, or
	 * '' when any part of the guard fails: the explicit switch, the install
	 * record, the declaration's portal against the named portal, and that
	 * portal offering the `nextcloud` mode.
	 *
	 * @param string $id     The example resident's id.
	 * @param string $portal The portal slug named by the caller.
	 *
	 * @return string The user id, or ''.
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	private function residentUser(string $id, string $portal): string {
		if ($this->signIns->open() === false || $portal === '') {
			return '';
		}

		try {
			$site = $this->portals->resolve(request: $this->request, portalSlug: $portal);
			if ($this->offersNextcloud(site: $site, portal: $portal) === false) {
				return '';
			}

			return $this->signIns->userFor(id: $id, portal: $site);
		} catch (\Throwable) {
			// A record, declaration or portal that cannot be read is no door.
			return '';
		}
	}//end residentUser()

	/**
	 * Whether the resolved portal is the named one and offers the
	 * `nextcloud` mode, the example resident's own way in.
	 *
	 * @param array<string, mixed>|null $site   The resolved portal record.
	 * @param string                    $portal The portal slug named by the caller.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	private function offersNextcloud(?array $site, string $portal): bool {
		if ($site === null || (string)($site['slug'] ?? '') !== $portal) {
			return false;
		}

		$modes = ($site['authentication']['modes'] ?? []);

		return is_array($modes) === true && in_array(needle: 'nextcloud', haystack: $modes, strict: true) === true;
	}//end offersNextcloud()
}//end class
