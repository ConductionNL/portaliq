<?php

/**
 * Portaliq Portal Embed Controller
 *
 * A portal intake form framed on somebody else's website: the frame route, and
 * the submission that comes back from it.
 *
 * Three things are deliberate here and each one is a rule in the spec. The
 * origin list is checked before the form is resolved, so a disallowed origin
 * never causes a schema read. The frame carries no session: it reads no bearer
 * and offers no login, so a visitor signed in to the portal in another tab is
 * anonymous inside it. And the submission takes the ordinary anonymous intake
 * path, so a case app needs no change for an embedded case to arrive.
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
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use OCA\Portaliq\Service\Intake\PortalEmbedThrottle;
use OCA\Portaliq\Service\Intake\PortalFormAvailability;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves the framed form and takes its submissions.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per step:
 * resolve the portal, the binding, the origin guard, the throttle, the
 * validator and the destination submit.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class PortalEmbedController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalResolver $portals Resolves the portal being framed.
	 * @param PortalFormBindingResolver $bindings Resolves the binding.
	 * @param PortalEmbedGuard $guard Decides who may frame this form.
	 * @param PortalEmbedThrottle $throttle Counts frame traffic per origin.
	 * @param PortalFormValidator $validator Validates a submission.
	 * @param DestinationIntake $intake Submits into the binding's destination (decision 179).
	 * @param IURLGenerator $urls Builds the follow link.
	 * @param PortalFormAvailability|null $availability Says whether the form takes submissions now.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalEmbedGuard $guard,
		private readonly PortalEmbedThrottle $throttle,
		private readonly PortalFormValidator $validator,
		private readonly DestinationIntake $intake,
		private readonly IURLGenerator $urls,
		private readonly ?PortalFormAvailability $availability = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The framed form, or a plain message.
	 *
	 * @param string $route The in-portal route of the form page.
	 *
	 * @return TemplateResponse The frame, always with `frame-ancestors` built
	 *                          from this form's own origin list.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function frame(string $route): TemplateResponse {
		$site = $this->portals->resolve(request: $this->request);
		$binding = null;
		if ($site !== null) {
			$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		}

		if ($binding === null) {
			return $this->refusal(binding: [], reason: 'form_not_found');
		}

		// The origin list is checked BEFORE the form is resolved. A disallowed
		// origin therefore causes no schema read and consults no contribution
		// provider: it gets a message and nothing else.
		if ($this->guard->allows(binding: $binding, origin: (string)$this->request->getHeader('Origin')) === false) {
			return $this->refusal(binding: $binding, reason: 'origin_not_allowed');
		}

		$unavailable = $this->availability?->situation(binding: $binding);
		if ($unavailable !== null) {
			return $this->refusal(binding: $binding, reason: 'form_unavailable', extra: ['unavailable' => $unavailable]);
		}

		$render = $this->bindings->render(binding: $binding);
		if (($render['resolvesToNoForm'] ?? false) === true) {
			return $this->refusal(binding: $binding, reason: 'form_not_published');
		}

		if ($this->bindings->requiredTrust(site: (array)$site, binding: $binding, render: $render) !== null) {
			// An identified intake is not something a frame can do: it would
			// mean a login inside somebody else's page. That holds for a
			// portal that requires it for every form and for one form that
			// requires DigiD or eHerkenning (portaliq#725). The visitor is
			// offered the portal's own page instead, and the frame renders no
			// field.
			return $this->refusal(
				binding: $binding,
				reason: 'identified_intake',
				extra: ['portalUrl' => $this->urls->linkToRouteAbsolute('portaliq.portalPage.site') . '?route=' . rawurlencode($route)]
			);
		}

		return $this->framed(
			binding: $binding,
			payload: [
				'route' => $route,
				'fields' => (array)($render['fields'] ?? []),
				'settings' => (array)($render['settings'] ?? []),
				// No prefill, ever: the frame reads no session, so there is
				// nobody to prefill from.
				'prefill' => [],
			]
		);
	}//end frame()

	/**
	 * A submission from the frame.
	 *
	 * @param string $route The form page submitted.
	 * @param array<string, mixed> $answers What the visitor answered.
	 * @param string $_hp The honeypot: a field the frame keeps hidden and empty.
	 *
	 * @return JSONResponse The reference and its follow link, or a refusal.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#2.2
	 *
	 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- `_hp` is the honeypot's
	 * name on the wire, the one OpenRegister's own form submit reads too.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function submit(string $route, array $answers = [], string $_hp = ''): JSONResponse {
		$site = $this->portals->resolve(request: $this->request);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$origin = (string)$this->request->getHeader('Origin');
		if ($this->guard->allows(binding: $binding, origin: $origin) === false) {
			return new JSONResponse(['error' => 'origin_not_allowed'], Http::STATUS_FORBIDDEN);
		}

		// The honeypot first: it costs nothing, and a filled one is a bot. It
		// gets the same 202 a person would see, and nothing is sent anywhere.
		if (trim($_hp) !== '') {
			return new JSONResponse(['state' => 'accepted'], Http::STATUS_ACCEPTED);
		}

		if ($this->throttle->allow(origin: $origin, address: (string)$this->request->getRemoteAddress()) === false) {
			return new JSONResponse(['error' => 'too_many_requests'], Http::STATUS_TOO_MANY_REQUESTS);
		}

		if ($this->availability?->situation(binding: $binding) !== null) {
			return new JSONResponse(['error' => 'form_unavailable'], Http::STATUS_CONFLICT);
		}

		$render = $this->bindings->render(binding: $binding);
		if ($this->bindings->requiredTrust(site: $site, binding: $binding, render: $render) !== null) {
			// The frame refuses a form that needs a sign-in, and so must its
			// submit: the refusal may not depend on the frame being used,
			// because anybody can POST here directly with an allowed Origin
			// (portaliq#725). This route reads no session, so no submission
			// to such a form is ever accepted here.
			return new JSONResponse(['error' => 'identified_intake'], Http::STATUS_FORBIDDEN);
		}

		$validated = $this->validator->validate(fields: (array)($render['fields'] ?? []), answers: $answers);
		if ($validated['valid'] === false) {
			return new JSONResponse(['errors' => $validated['errors']], Http::STATUS_BAD_REQUEST);
		}

		return $this->send(site: $site, binding: $binding, render: $render, answers: $validated['answers'], origin: $origin);
	}//end submit()

	/**
	 * Submit the frame's validated answers into the binding's destination.
	 *
	 * @param array<string, mixed> $site    The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render  What the binding renders to.
	 * @param array<string, mixed> $answers The answers the portal's checks accepted.
	 * @param string               $origin  The framing origin.
	 *
	 * @return JSONResponse The reference, its moments and follow link, or the refusal.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#2.2
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) -- DestinationIntake::refusal is the one
	 * refusal shape the intake and the embed submit share.
	 */
	private function send(array $site, array $binding, array $render, array $answers, string $origin): JSONResponse {
		// Nothing here reads a bearer: a submission from the frame is
		// anonymous even when the visitor is signed in elsewhere.
		$submitted = $this->intake->submit(
			binding: $binding,
			render: $render,
			answers: $answers,
			server: ['portal' => (string)($site['slug'] ?? ''), 'origin' => $origin],
			idempotencyKey: (string)$this->request->getHeader('Idempotency-Key')
		);
		if ($submitted['ok'] === false) {
			$refusal = DestinationIntake::refusal(submitted: $submitted);
			return new JSONResponse($refusal['body'], $refusal['status']);
		}

		$answer    = $submitted['answer'];
		$reference = (string)($answer['reference'] ?? '');

		return new JSONResponse(
			array_merge(
				(array)($answer['confirmation'] ?? []),
				[
					'reference' => $reference,
					'receivedAt' => (string)($answer['receivedAt'] ?? ''),
					'state' => 'registered',
					'confirmationText' => (string)($render['settings']['confirmationText'] ?? ''),
					// Something to come back with, on the portal's own page
					// rather than inside the frame.
					'followUrl' => $this->urls->linkToRouteAbsolute('portaliq.portalPage.site') . '?reference=' . rawurlencode($reference),
				]
			)
		);
	}//end send()

	/**
	 * The frame, with this form's own `frame-ancestors`.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 * @param array<string, mixed> $payload What the frame renders.
	 *
	 * @return TemplateResponse
	 */
	private function framed(array $binding, array $payload): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'embed',
			['embed' => $payload, 'minHeight' => PortalEmbedGuard::MINIMUM_HEIGHT],
			TemplateResponse::RENDER_AS_BLANK
		);

		$csp = new ContentSecurityPolicy();
		// The 'self' default is cleared first: without that, a form with no
		// allowed origins would still allow same-origin framing instead of
		// none at all.
		$csp->disallowFrameAncestorDomain('\'self\'');
		foreach ($this->guard->allowedOrigins(binding: $binding) as $origin) {
			$csp->addAllowedFrameAncestorDomain($origin);
		}

		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end framed()

	/**
	 * A frame that renders a message and no form.
	 *
	 * @param array<string, mixed> $binding The binding, or [] when there is none.
	 * @param string $reason Why nothing is rendered.
	 * @param array<string, mixed> $extra Anything the message needs.
	 *
	 * @return TemplateResponse
	 */
	private function refusal(array $binding, string $reason, array $extra = []): TemplateResponse {
		return $this->framed(
			binding: $binding,
			payload: array_merge(['refused' => $reason, 'fields' => [], 'prefill' => []], $extra)
		);
	}//end refusal()
}//end class
