<?php

/**
 * Portaliq Desk Session Controller
 *
 * "Inloggen met werkaccount": an employee in the portal's desk group turns
 * their Nextcloud session into a portal session for filling one kind of form
 * in for a resident or a company.
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
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Service\Intake\OnBehalfFilling;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Nextcloud checks the password; this only checks the desk group and the form.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
 */
class DeskSessionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                           $request      The request.
	 * @param IUserSession                       $userSession  The caller's Nextcloud session.
	 * @param IURLGenerator                      $urlGenerator Builds the login and return addresses.
	 * @param PortalResolverInterface            $portals      Resolves the portal.
	 * @param PortalFormBindingResolverInterface $bindings     Resolves the form.
	 * @param PortalSessionServiceInterface      $session      Mints the portal session.
	 * @param OnBehalfFilling                    $onBehalf     The desk group and form checks.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalResolverInterface $portals,
		private readonly PortalFormBindingResolverInterface $bindings,
		private readonly PortalSessionServiceInterface $session,
		private readonly OnBehalfFilling $onBehalf,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Start a desk session for one form.
	 *
	 * Not signed in to Nextcloud: 302 to Nextcloud's own login form, back here
	 * afterwards. A portal or form that does not exist, or a form that does
	 * not allow staff: 404. A user outside the portal's desk group: 403
	 * `not_desk_staff`. Otherwise 302 to `returnTo` (a path on this instance)
	 * or the portal's site, with the bearer in the URL fragment.
	 *
	 * The session's trust is `low`, like every Nextcloud sign-in; the intake
	 * accepts it only for a form that allows staff and only while the user is
	 * still in the desk group.
	 *
	 * @param string $portal   The portal's slug.
	 * @param string $route    The form page.
	 * @param string $returnTo Where to send the browser afterwards: a path on this instance.
	 *
	 * @return Response
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function start(string $portal = '', string $route = '', string $returnTo = ''): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $this->request->getRequestUri()]),
				Http::STATUS_FOUND
			);
		}

		$slug = null;
		if ($portal !== '') {
			$slug = $portal;
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: $slug);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null || $this->onBehalf->offered(binding: $binding) === false) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$employee = $this->onBehalf->employee(site: $site, binding: $binding, uid: $user->getUID());
		if ($employee === null) {
			return new JSONResponse(['error' => 'not_desk_staff'], Http::STATUS_FORBIDDEN);
		}

		$issued = $this->session->issueSession(
			subjectRef: $employee['uid'],
			audience: OnBehalfFilling::AUDIENCE,
			organisation: (string)($site['organisation'] ?? ''),
			trust: 'low',
			roles: [OnBehalfFilling::AUDIENCE . ':fill'],
			provider: OnBehalfFilling::PROVIDER
		);
		if ($issued === null) {
			return new JSONResponse(['error' => 'not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$target = '/apps/portaliq/site?portal=' . rawurlencode((string)($site['slug'] ?? ''));
		if (self::isLocalPath(path: $returnTo) === true) {
			$target = $returnTo;
		}

		// The bearer travels in the fragment, which never reaches a server or a log.
		return new RedirectResponse(
			$this->urlGenerator->getAbsoluteURL($target) . '#token=' . rawurlencode((string)$issued['token']),
			Http::STATUS_FOUND
		);
	}//end start()

	/**
	 * Whether a return address is a path on this instance, not another host.
	 *
	 * @param string $path The requested return address.
	 *
	 * @return bool
	 */
	private static function isLocalPath(string $path): bool {
		return str_starts_with($path, '/') === true
			&& str_starts_with($path, '//') === false
			&& str_contains($path, '\\') === false
			&& preg_match('/[\x00-\x1F]/', $path) !== 1;
	}//end isLocalPath()
}//end class
