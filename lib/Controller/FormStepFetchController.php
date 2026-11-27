<?php

/**
 * Portaliq Form Step Fetch Controller (data-lookups-and-checks-in-forms)
 *
 * Runs one fetch a form step declares, at the step change, from the server.
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Service\Intake\FormSignInGate;
use OCA\Portaliq\Service\Intake\FormStepFetches;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The fetch route of a form step: integriq asks the connection from the
 * server and the step gets the fields it fills and its check line. It asks
 * the same portal, binding and sign-in level as the form itself.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
class FormStepFetchController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest                           $request     The request.
	 * @param PortalResolverInterface            $portals     Resolves the portal being visited.
	 * @param PortalSessionServiceInterface      $session     Resolves the subject behind the bearer.
	 * @param PortalFormBindingResolverInterface $bindings    Resolves and renders a form binding.
	 * @param FormSignInGate                     $signInGate  Refuses a session below the form's sign-in level.
	 * @param FormStepFetches                    $stepFetches Runs the fetch a step declares through integriq.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolverInterface $portals,
		private readonly PortalSessionServiceInterface $session,
		private readonly PortalFormBindingResolverInterface $bindings,
		private readonly FormSignInGate $signInGate,
		private readonly FormStepFetches $stepFetches,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Run one fetch a step declares, at the step change: integriq asks the
	 * connection from the server and the step gets the fields it fills and
	 * its check line. The source, its credential and its raw answer never
	 * reach the browser; a fetch that does not answer is a 503, so the step
	 * shows that the data cannot be fetched now and the draft stays.
	 *
	 * @param string               $route   The form page.
	 * @param string               $step    The id of the step that declares the fetch.
	 * @param string               $fetch   The id of the fetch.
	 * @param array<string, mixed> $answers The answers so far.
	 * @param string               $portal  The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{fetch, values, check, fetchedAt}`, 503 when the fetch does not answer, or 404.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function fetch(string $route, string $step, string $fetch, array $answers = [], string $portal = ''): JSONResponse {
		$site = $this->site(portal: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$render  = $this->bindings->render(binding: $binding);
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		$refusal = $this->signInGate->refusal(site: $site, binding: $binding, render: $render, subject: $subject);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->stepFetches->answerForStep(render: $render, step: $step, fetch: $fetch, answers: $answers);
	}//end fetch()

	/**
	 * The portal being visited, or null.
	 *
	 * @param string $portal The portal's slug, or empty for the host.
	 *
	 * @return array<string, mixed>|null
	 */
	private function site(string $portal): ?array {
		if ($portal === '') {
			return $this->portals->resolve(request: $this->request, portalSlug: null);
		}

		return $this->portals->resolve(request: $this->request, portalSlug: $portal);
	}//end site()
}//end class
