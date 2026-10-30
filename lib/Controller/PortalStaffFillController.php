<?php

/**
 * Portaliq Portal Staff Fill Controller
 *
 * An employee fills a form in for a resident or a company, signed in with
 * their work account (resident-identity-in-forms REQ-RIF-005, board
 * FormulierInloggen "Voor medewerkers"). Every route needs a signed-in
 * Nextcloud user, and every route checks that this user is in the portal's
 * desk group and that the form allows employees before it reads or writes
 * anything. The writes are CSRF checked by the framework.
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\FormConfirmationMailer;
use OCA\Portaliq\Service\Intake\FormStatements;
use OCA\Portaliq\Service\Intake\IntakeSend;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormCalculator;
use OCA\Portaliq\Service\Intake\PortalFormDecision;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\PortalIntakeConfirmation;
use OCA\Portaliq\Service\Intake\PortalSubmissionChecks;
use OCA\Portaliq\Service\Intake\StaffFill;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Staff mode on a portal form: may I, start for an applicant, send.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per step
 * of the intake, as PortalIntakeController.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class PortalStaffFillController extends Controller {
	/**
	 * The checks between a validated form and a delivered submission.
	 *
	 * @var PortalSubmissionChecks
	 */
	private readonly PortalSubmissionChecks $checks;

	/**
	 * Delivers a prepared submission and mails the confirmation.
	 *
	 * @var IntakeSend
	 */
	private readonly IntakeSend $sender;

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request           The request.
	 * @param PortalResolver               $portals           Resolves the portal being visited.
	 * @param PortalFormBindingResolver    $bindings          Resolves the binding.
	 * @param StaffFill                    $staff             Decides staff mode and fills the applicant in.
	 * @param PortalFormValidator          $validator         Validates a submission.
	 * @param FormStatements|null          $statements        Resolves and checks the statements a form asks.
	 * @param PortalFormCalculator|null    $calculator        Works out the calculated fields again on submit.
	 * @param PortalFormDecision|null      $decision          Asks the rule engine for the form's decisions.
	 * @param PortalEmailVerification|null $emailVerification Checks the proof that an e-mail address was verified.
	 * @param DestinationIntake|null       $intake            Submits the answers into the binding's destination.
	 * @param FormConfirmationMailer|null  $confirmationMail  Mails the confirmation.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalFormBindingResolver $bindings,
		private readonly StaffFill $staff,
		PortalFormValidator $validator,
		?FormStatements $statements = null,
		?PortalFormCalculator $calculator = null,
		?PortalFormDecision $decision = null,
		?PortalEmailVerification $emailVerification = null,
		?DestinationIntake $intake = null,
		?FormConfirmationMailer $confirmationMail = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->checks = new PortalSubmissionChecks(
			validator: $validator,
			family: null,
			statements: $statements,
			calculator: $calculator,
			decision: $decision,
			emailVerification: $emailVerification
		);
		$this->sender = new IntakeSend(intake: $intake, confirmation: new PortalIntakeConfirmation(confirmationMail: $confirmationMail));
	}//end __construct()

	/**
	 * Whether the signed-in user may fill this form in for someone else.
	 *
	 * @param string $route  The form's binding route.
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{filledBy: {uid, displayName}}`, 403 `staff_not_allowed` or 404.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employee(string $route, string $portal = ''): JSONResponse {
		$found = $this->allowed(route: $route, portal: $portal);
		if ($found instanceof JSONResponse) {
			return $found;
		}

		return new JSONResponse(['filledBy' => $found['employee']]);
	}//end employee()

	/**
	 * Start the form for an applicant: the form, with the applicant block
	 * filled in from the BRP or the KvK. The lookup is written to the audit
	 * trail with the employee's name.
	 *
	 * @param string               $route     The form's binding route.
	 * @param string               $portal    The portal's slug; empty resolves it from the host.
	 * @param array<string, mixed> $applicant `kind` (bsn, kvk or named) and `value`.
	 *
	 * @return JSONResponse The render with `prefill`, `applicant` and `filledBy`; 400 `applicant_invalid`, 403 or 404.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function start(string $route, string $portal = '', array $applicant = []): JSONResponse {
		$found = $this->allowed(route: $route, portal: $portal);
		if ($found instanceof JSONResponse) {
			return $found;
		}

		$who = $this->staff->applicant(input: $applicant);
		if ($who === null) {
			return new JSONResponse(['error' => 'applicant_invalid'], Http::STATUS_BAD_REQUEST);
		}

		$render = $found['render'];
		$render['prefill']    = $this->staff->prefill(employee: $found['employee'], applicant: $who, site: $found['site']);
		$render['statements'] = $this->checks->askedStatements(render: $render, site: $found['site']);
		$render['steps']      = $this->checks->stepsForTheBrowser(steps: (array)($render['steps'] ?? []));
		$render['applicant']  = $who;
		$render['filledBy']   = $found['employee'];

		return new JSONResponse($render);
	}//end start()

	/**
	 * Send the form for an applicant. The request carries the applicant as
	 * applicant and the employee as `filledBy`; a resident with a portal
	 * account finds it in their own Mijn zaken.
	 *
	 * @param string                $route          The form's binding route.
	 * @param array<string, mixed>  $applicant      `kind` (bsn, kvk or named) and `value`.
	 * @param array<string, mixed>  $answers        What the employee filled in.
	 * @param string                $portal         The portal's slug; empty resolves it from the host.
	 * @param array<int, string>    $statements     The keys of the statements ticked.
	 * @param array<string, string> $verifiedEmails Proofs of verified e-mail addresses, by address.
	 *
	 * @return JSONResponse The reference, the per-field errors, or a refusal.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function submit(
		string $route,
		array $applicant = [],
		array $answers = [],
		string $portal = '',
		array $statements = [],
		array $verifiedEmails = [],
	): JSONResponse {
		$found = $this->allowed(route: $route, portal: $portal);
		if ($found instanceof JSONResponse) {
			return $found;
		}

		$who = $this->staff->applicant(input: $applicant);
		if ($who === null) {
			return new JSONResponse(['error' => 'applicant_invalid'], Http::STATUS_BAD_REQUEST);
		}

		$prepared = $this->checks->prepare(
			render: $found['render'],
			site: $found['site'],
			route: $route,
			answers: $answers,
			subject: null,
			statements: $statements,
			verifiedEmails: $verifiedEmails
		);
		if ($prepared instanceof JSONResponse) {
			return $prepared;
		}

		return $this->sender->send(
			site: $found['site'],
			binding: $found['binding'],
			render: $found['render'],
			prepared: $prepared,
			subjectRef: $this->staff->subjectRefFor(applicant: $who),
			request: [
				'origin' => (string)$this->request->getHeader('Origin'),
				'idempotencyKey' => (string)$this->request->getHeader('Idempotency-Key'),
				'server' => ['filledBy' => $found['employee'], 'applicant' => $who],
			]
		);
	}//end submit()

	/**
	 * The portal, binding, render and employee when staff mode is allowed on
	 * this form for the signed-in user, or the refusal.
	 *
	 * @param string $route  The form's binding route.
	 * @param string $portal The portal's slug, or '' for the host.
	 *
	 * @return array<string, array<string, mixed>>|JSONResponse `site`, `binding`, `render` and `employee`, or the refusal.
	 */
	private function allowed(string $route, string $portal): array|JSONResponse {
		$slug = null;
		if ($portal !== '') {
			$slug = $portal;
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: $slug);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$employee = $this->staff->employee(site: $site, binding: $binding);
		if ($employee === null) {
			return new JSONResponse(['error' => 'staff_not_allowed'], Http::STATUS_FORBIDDEN);
		}

		$render = $this->bindings->render(binding: $binding);
		if (($render['resolvesToNoForm'] ?? false) === true || ($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL) {
			return new JSONResponse(['error' => 'form_not_submittable'], Http::STATUS_FORBIDDEN);
		}

		return ['site' => $site, 'binding' => $binding, 'render' => $render, 'employee' => $employee];
	}//end allowed()
}//end class
