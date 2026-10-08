<?php

/**
 * Portaliq Portal Form Email Code Controller
 *
 * Sends and checks the code that proves a form's e-mail address is the
 * resident's.
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
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * A code is sent only for a form that exists on this portal and asks for the
 * address to be verified, so the route is not a way to mail strangers.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
class PortalFormEmailCodeController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalResolver $portals Resolves the portal being visited.
	 * @param PortalFormBindingResolver $bindings Resolves the form.
	 * @param PortalEmailVerification $verification The code rules.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalEmailVerification $verification,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send a code to the address.
	 *
	 * @param string $route The form page.
	 * @param string $email The address to check.
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{sent: true, resendAfter}`, or an error: 400, 404, 429 or 503.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function send(string $route='', string $email='', string $portal=''): JSONResponse {
		$site = $this->portals->resolve(request: $this->request, portalSlug: $this->slugOrNull(slug: $portal));
		if ($site === null || $this->formAsksForVerification(site: $site, route: $route) === false) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->verification->request(site: $site, route: $route, email: $email, client: $this->request->getRemoteAddress());
		$status = match ($result) {
			PortalEmailVerification::SENT => Http::STATUS_OK,
			PortalEmailVerification::INVALID => Http::STATUS_BAD_REQUEST,
			PortalEmailVerification::WAIT, PortalEmailVerification::THROTTLED => Http::STATUS_TOO_MANY_REQUESTS,
			default => Http::STATUS_SERVICE_UNAVAILABLE,
		};
		if ($status !== Http::STATUS_OK) {
			return new JSONResponse(['error' => $result], $status);
		}

		return new JSONResponse(['sent' => true, 'resendAfter' => PortalEmailVerification::RESEND_AFTER]);
	}//end send()

	/**
	 * Check the code the resident typed.
	 *
	 * @param string $route The form page.
	 * @param string $email The address.
	 * @param string $code The six digits.
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{verified: true, proof}`, or 400 with the reason.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function check(string $route='', string $email='', string $code='', string $portal=''): JSONResponse {
		$site = $this->portals->resolve(request: $this->request, portalSlug: $this->slugOrNull(slug: $portal));
		if ($site === null || $this->formAsksForVerification(site: $site, route: $route) === false) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->verification->check(portal: (string)($site['slug'] ?? ''), route: $route, email: $email, code: $code);
		if ($result['ok'] === false) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['verified' => true, 'proof' => $result['proof']]);
	}//end check()

	/**
	 * Whether the route is a form of this portal with a field that verifies its address.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param string $route The form page.
	 *
	 * @return bool
	 */
	private function formAsksForVerification(array $site, string $route): bool {
		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return false;
		}

		$render = $this->bindings->render(binding: $binding);
		foreach ((array)($render['fields'] ?? []) as $field) {
			if (is_array($field) === true && ($field['type'] ?? '') === 'email' && ($field['verify'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end formAsksForVerification()

	/**
	 * A slug, or null for the host's portal.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null
	 */
	private function slugOrNull(string $slug): ?string {
		if ($slug === '') {
			return null;
		}

		return $slug;
	}//end slugOrNull()
}//end class
