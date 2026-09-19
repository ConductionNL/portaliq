<?php

/**
 * Portaliq Portal Intake Controller
 *
 * The citizen's way into a case: the entry point listing the published
 * catalogue, the form page a binding resolves to, the submission, and the
 * reference page afterwards.
 *
 * Every route here is anonymous by design. A signed-in citizen gets their own
 * applicant block filled in; a visitor with no session gets an empty one, and
 * nothing in the answer says a value existed.
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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Renders the intake form, takes the submission and reports on it.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per step
 * of the intake: resolve, prefill, validate, challenge, queue, list.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class PortalIntakeController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalResolver $portals Resolves the portal being visited.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalFormBindingResolver $bindings Resolves the binding.
	 * @param PortalApplicantPrefill $prefill Fills the applicant block.
	 * @param PortalFormValidator $validator Validates a submission.
	 * @param PortalIntakeQueue $queue Records and reports on submissions.
	 * @param PortalChallengeService $challenge The portal's own challenge.
	 * @param PortalCatalogueReader $catalogue The published request entries.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalSessionService $session,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalApplicantPrefill $prefill,
		private readonly PortalFormValidator $validator,
		private readonly PortalIntakeQueue $queue,
		private readonly PortalChallengeService $challenge,
		private readonly PortalCatalogueReader $catalogue,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The entry point: the published catalogue, by topic.
	 *
	 * @return JSONResponse The topics and their requests.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function catalogue(): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['topics' => $this->catalogue->topicsFor(portal: (string)($site['slug'] ?? ''))]);
	}//end catalogue()

	/**
	 * The form a route is bound to, as it stands right now.
	 *
	 * @param string $route The in-portal route of the form page.
	 *
	 * @return JSONResponse The render payload, or a refusal.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function form(string $route): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$render = $this->bindings->render(binding: $binding);
		$render['prefill'] = $this->prefill->forSubject(
			subject: $this->subject(),
			fields: (array)($render['fields'] ?? [])
		);

		if (($render['settings']['challenge'] ?? false) === true) {
			$render['challenge'] = $this->challenge->issue(site: $site, surface: 'form');
		}

		return new JSONResponse($render);
	}//end form()

	/**
	 * Submit a form.
	 *
	 * @param string $route The form page submitted.
	 * @param array<string, mixed> $answers What the citizen answered.
	 * @param string $nonce The challenge nonce, when one was issued.
	 * @param string $solution The solution to it.
	 * @param int $expiresAt The expiry issued with the nonce.
	 * @param string $signature This instance's signature over the nonce.
	 *
	 * @return JSONResponse The reference, the per-field errors, or a refusal.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function submit(
		string $route,
		array $answers = [],
		string $nonce = '',
		string $solution = '',
		int $expiresAt = 0,
		string $signature = '',
	): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$render = $this->bindings->render(binding: $binding);
		if (($render['resolvesToNoForm'] ?? false) === true || ($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL) {
			// An external intake is filled in at its own host; the portal has
			// nothing to accept here.
			return new JSONResponse(['error' => 'form_not_submittable'], Http::STATUS_FORBIDDEN);
		}

		if (($render['settings']['challenge'] ?? false) === true) {
			$accepted = $this->challenge->accepts(
				site: $site,
				surface: 'form',
				submission: $answers,
				nonce: $nonce,
				solution: $solution,
				expiresAt: $expiresAt,
				signature: $signature
			);
			if ($accepted === false) {
				return new JSONResponse(['error' => 'challenge_failed'], Http::STATUS_FORBIDDEN);
			}
		}

		// Validation happens here, before anything is recorded and long before
		// any case app is called: an invalid submission never reaches one.
		$validated = $this->validator->validate(fields: (array)($render['fields'] ?? []), answers: $answers);
		if ($validated['valid'] === false) {
			return new JSONResponse(['errors' => $validated['errors']], Http::STATUS_BAD_REQUEST);
		}

		$subject = $this->subject();
		$accepted = $this->queue->accept(
			portal: (string)($site['slug'] ?? ''),
			route: $route,
			answers: $validated['answers'],
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			origin: (string)$this->request->getHeader('Origin')
		);
		if ($accepted === null) {
			return new JSONResponse(['error' => 'not_accepted'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse([
			'reference' => $accepted['reference'],
			'state' => $accepted['state'],
			'confirmationText' => (string)($render['settings']['confirmationText'] ?? ''),
		]);
	}//end submit()

	/**
	 * What became of a submission.
	 *
	 * @param string $reference The reference the citizen was given.
	 *
	 * @return JSONResponse The real state, including a create that failed.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function status(string $reference): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$status = $this->queue->status(reference: $reference, portal: (string)($site['slug'] ?? ''));
		if ($status === null) {
			return new JSONResponse(['error' => 'reference_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($status);
	}//end status()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()

	/**
	 * The portal being visited, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function site(): ?array {
		return $this->portals->resolve(request: $this->request);
	}//end site()
}//end class
