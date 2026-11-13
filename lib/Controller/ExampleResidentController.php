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
 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\ExampleResidentSignInInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccountLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
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
 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */
class ExampleResidentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request      The request object.
	 * @param PortalSessionService  $session      Mints the portal session.
	 * @param PortalAccountLookupInterface   $accounts     Finds the resident's `portalAccount`.
	 * @param IURLGenerator         $urlGenerator Builds the final SPA redirect.
	 * @param PortalResolverInterface        $portals      Resolves the portal being signed in to.
	 * @param ExampleResidentSignInInterface $signIns      The demo switch and the installed resident of a portal.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalAccountLookupInterface $accounts,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalResolverInterface $portals,
		private readonly ExampleResidentSignInInterface $signIns,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * @param string $id       The example set's id (`zuiddrecht`).
	 * @param string $portal   The portal slug to sign in to.
	 * @param string $returnTo Where to send the browser afterwards.
	 * @param string $resident The resident's key in the set (`linda`); '' for the first one.
	 *
	 * @return Response A redirect carrying the bearer in the URL fragment, or 404.
	 *
	 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[BruteForceProtection(action: 'portaliq_example_resident_login')]
	public function signIn(string $id = '', string $portal = '', string $returnTo = '', string $resident = ''): Response {
		$userId  = $this->residentUser(id: $id, portal: $portal, resident: $resident);
		$account = $this->activeAccount(userId: $userId);
		if ($account === null) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['reason' => 'example_resident_login_closed']);
			return $response;
		}

		$issued = $this->issuedSession(userId: $userId, account: $account);
		if ($issued === null) {
			return new JSONResponse(['error' => 'not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new RedirectResponse(
			$this->urlGenerator->getAbsoluteURL($this->landing(portal: $portal, returnTo: $returnTo)) . '#token=' . rawurlencode(string: $issued['token']),
			Http::STATUS_FOUND
		);
	}//end signIn()

	/**
	 * The resident's portal account when it is active, else null (also for
	 * no resident at all).
	 *
	 * @param string $userId The resident's account id, '' for none.
	 *
	 * @return array<string, mixed>|null
	 */
	private function activeAccount(string $userId): ?array {
		if ($userId === '') {
			return null;
		}

		$account = $this->accounts->bySubjectRef(subjectRef: $userId);
		if ($account === null || ($account['status'] ?? 'active') !== 'active') {
			return null;
		}

		return $account;
	}//end activeAccount()

	/**
	 * A session for the resident's account, or null when none can be issued.
	 *
	 * @param string               $userId  The resident's account id.
	 * @param array<string, mixed> $account The active account.
	 *
	 * @return array<string, mixed>|null
	 */
	private function issuedSession(string $userId, array $account): ?array {
		$audience = (string)($account['audience'] ?? 'client');
		return $this->session->issueSession(
			subjectRef: $userId,
			audience: $audience,
			organisation: (string)($account['organisation'] ?? 'dev-org'),
			// No password was asked: the lowest assurance, as dev-login and
			// the nextcloud mode carry.
			trust: 'low',
			roles: [$audience . ':read']
		);
	}//end issuedSession()

	/**
	 * Where the signed-in resident lands: the given path, else the portal's
	 * site.
	 *
	 * @param string $portal   The portal slug.
	 * @param string $returnTo The asked return path, '' for none.
	 *
	 * @return string
	 */
	private function landing(string $portal, string $returnTo): string {
		if ($returnTo !== '') {
			return $returnTo;
		}

		return '/apps/portaliq/site?portal=' . rawurlencode(string: $portal);
	}//end signIn()

	/**
	 * The Nextcloud account id the one-click demo sign-in may mint for, or
	 * '' when any part of the guard fails: the explicit switch, the install
	 * record, the declaration's portal against the named portal, and that
	 * portal offering the `nextcloud` mode.
	 *
	 * @param string $id       The example set's id.
	 * @param string $portal   The portal slug named by the caller.
	 * @param string $resident The resident's key, or '' for the first one.
	 *
	 * @return string The user id, or ''.
	 *
	 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	private function residentUser(string $id, string $portal, string $resident): string {
		if ($this->signIns->open() === false || $portal === '') {
			return '';
		}

		try {
			$site = $this->portals->resolve(request: $this->request, portalSlug: $portal);
			if ($this->offersNextcloud(site: $site, portal: $portal) === false) {
				return '';
			}

			return $this->signIns->userFor(id: $id, portal: $site, resident: $resident);
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
	 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	private function offersNextcloud(?array $site, string $portal): bool {
		if ($site === null || (string)($site['slug'] ?? '') !== $portal) {
			return false;
		}

		$modes = ($site['authentication']['modes'] ?? []);

		return is_array($modes) === true && in_array(needle: 'nextcloud', haystack: $modes, strict: true) === true;
	}//end offersNextcloud()
}//end class
