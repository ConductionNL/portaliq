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
use OCA\Portaliq\Service\Intake\FormConfirmationMailer;
use OCA\Portaliq\Service\Intake\FormConfirmationSummary;
use OCA\Portaliq\Service\Intake\FormStatements;
use OCA\Portaliq\Service\Intake\PortalAddressLookup;
use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Intake\PortalFamilyMembers;
use OCA\Portaliq\Service\Intake\PortalFee;
use OCA\Portaliq\Service\Intake\PortalPaymentIntents;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormCalculator;
use OCA\Portaliq\Service\Intake\PortalFormDecision;
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
use Throwable;

/**
 * Renders the intake form, takes the submission and reports on it.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per step
 * of the intake: resolve, prefill, validate, challenge, queue, list.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 * @SuppressWarnings(PHPMD.StaticAccess)            -- PortalSessionService::trustSatisfies,
 * the one trust ordering every portal gate shares.
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
	 * @param PortalAddressLookup|null $addresses Finds street and town for a postcode and number.
	 * @param PortalFamilyMembers|null $family Lists and re-checks the resident's family from the BRP.
	 * @param FormStatements|null $statements Resolves and checks the statements a form asks.
	 * @param FormConfirmationMailer|null $confirmationMail Mails the resident the reference and a summary.
	 * @param PortalFormCalculator|null $calculator Works out the form's calculated fields again on submit.
	 * @param PortalFormDecision|null $decision Asks the rule engine for the decisions a form's steps declare.
	 * @param PortalFee|null $fees Checks a fee and the address a resident may be sent to pay at.
	 * @param PortalPaymentIntents|null $intents Reads the state of a payment.
	 * @param PortalContributionRegistry|null $registry Finds the case app's pay action in the subject's own manifest.
	 * @param PortalActionForwarder|null $forwarder Forwards the pay action with a server-built body.
	 * @param PortalDeepLinkBuilder|null $deepLinks Builds the address the resident returns to.
	 * @param PortalEmailVerification|null $emailVerification Checks the proof that an e-mail address was verified.
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
		private readonly ?PortalAddressLookup $addresses = null,
		private readonly ?PortalFamilyMembers $family = null,
		private readonly ?FormStatements $statements = null,
		private readonly ?FormConfirmationMailer $confirmationMail = null,
		private readonly ?PortalFormCalculator $calculator = null,
		private readonly ?PortalFormDecision $decision = null,
		private readonly ?PortalFee $fees = null,
		private readonly ?PortalPaymentIntents $intents = null,
		private readonly ?PortalContributionRegistry $registry = null,
		private readonly ?PortalActionForwarder $forwarder = null,
		private readonly ?PortalDeepLinkBuilder $deepLinks = null,
		private readonly ?PortalEmailVerification $emailVerification = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The entry point: the published catalogue, by topic.
	 *
	 * @param string $portal The portal's slug, as the site renderer names it inside Nextcloud; empty resolves the portal from the host.
	 *
	 * @return JSONResponse The topics and their requests.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function catalogue(string $portal = ''): JSONResponse {
		$site = $this->site(portal: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['topics' => $this->catalogue->topicsFor(portal: (string)($site['slug'] ?? ''))]);
	}//end catalogue()

	/**
	 * Street and town for a postcode and house number.
	 *
	 * Public, because forms can be anonymous, and throttled per client. A miss
	 * and a register that cannot be read answer the same 404, so the form
	 * falls back to typing the street and town by hand.
	 *
	 * @param string $postcode The postcode.
	 * @param string $number   The house number.
	 * @param string $letter   The house letter.
	 * @param string $addition The addition.
	 *
	 * @return JSONResponse `{street, town}` or 404.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
	 *
	 * @no-admin-idor-exempt Postcode and house number are public address data from the
	 * BAG, not a tenant's object: the lookup answers street and town for any caller and
	 * touches no account, case or organisation record. It is rate limited per client.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function address(string $postcode='', string $number='', string $letter='', string $addition=''): JSONResponse {
		$found = $this->addresses?->find(postcode: $postcode, number: $number, letter: $letter, addition: $addition);
		if ($found === null) {
			return new JSONResponse(['error' => 'address_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($found);
	}//end address()

	/**
	 * The form a route is bound to, as it stands right now.
	 *
	 * @param string $route The in-portal route of the form page.
	 * @param string $portal The portal's slug, as the site renderer names it inside Nextcloud; empty resolves the portal from the host.
	 *
	 * @return JSONResponse The render payload, or a refusal.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function form(string $route, string $portal = ''): JSONResponse {
		$site = $this->site(portal: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$render = $this->bindings->render(binding: $binding);
		$subject = $this->subject();
		$refusal = $this->signInRefusal(site: $site, binding: $binding, render: $render, subject: $subject);
		if ($refusal !== null) {
			return $refusal;
		}

		$render['prefill'] = $this->prefill->forSubject(
			subject: $subject,
			fields: (array)($render['fields'] ?? [])
		);

		$render['statements'] = $this->askedStatements(render: $render, site: $site);
		$render['steps']      = $this->stepsForTheBrowser(steps: (array)($render['steps'] ?? []));

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
	 * @param string $portal The portal's slug, as the site renderer names it inside Nextcloud; empty resolves the portal from the host.
	 * @param array<int, string> $statements The keys of the statements the citizen ticked.
	 * @param array<string, string> $verifiedEmails Proofs of verified e-mail addresses, by address.
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
		string $portal = '',
		array $statements = [],
		array $verifiedEmails = [],
	): JSONResponse {
		$site = $this->site(portal: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$render = $this->bindings->render(binding: $binding);
		$subject = $this->subject();
		$refusal = $this->submitRefusal(site: $site, binding: $binding, render: $render, subject: $subject);
		if ($refusal !== null) {
			return $refusal;
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

		// A chosen family member is checked against the BRP again here, so a
		// reference the browser invented or kept from another day never
		// reaches a case (data-lookups-and-checks-in-forms REQ-DIF-004).
		$familyErrors = $this->familyErrors(fields: (array)($render['fields'] ?? []), answers: $validated['answers'], subject: $subject);
		if ($familyErrors !== []) {
			return new JSONResponse(['errors' => $familyErrors], Http::STATUS_BAD_REQUEST);
		}

		// An address the form asks to verify is refused without the proof of its code
		// (resident-identity-in-forms REQ-RIF-002).
		$unverified = $this->unverifiedEmails(render: $render, answers: $validated['answers'], proofs: $verifiedEmails, site: $site, route: $route);
		if ($unverified !== []) {
			return new JSONResponse(['errors' => $unverified], Http::STATUS_BAD_REQUEST);
		}

		// A calculated value is worked out again here and a decision is asked of the
		// rule engine again, whatever the browser sent (form-flow-repeating-groups-
		// calculations-and-decisions REQ-FFL-002, REQ-FFL-003).
		$worked = $this->workedOut(render: $render, answers: $validated['answers']);
		if ($worked === null) {
			return new JSONResponse(['error' => 'decision_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$validated['answers'] = $worked['answers'];

		// The statements the form asks are accepted before anything is
		// recorded, and each accepted one is recorded with the version of its
		// text (form-statements-intro-and-confirmation-mail REQ-FCI-002).
		$asked = $this->askedStatements(render: $render, site: $site);
		$checked = ['errors' => [], 'record' => []];
		if ($asked !== []) {
			if ($this->statements === null) {
				return new JSONResponse(['error' => 'statements_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			$checked = $this->statements->check(asked: $asked, accepted: $statements);
			if ($checked['errors'] !== []) {
				$errors = [];
				foreach ($checked['errors'] as $key => $message) {
					$errors['statement-' . $key] = $message;
				}

				return new JSONResponse(['errors' => $errors], Http::STATUS_BAD_REQUEST);
			}
		}

		$accepted = $this->queue->accept(
			portal: (string)($site['slug'] ?? ''),
			route: $route,
			answers: $validated['answers'],
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			origin: (string)$this->request->getHeader('Origin'),
			statements: $checked['record'],
			computed: $worked['computed'],
			decisions: $worked['decisions'],
			verifiedEmails: $this->verifiedRecord(render: $render, answers: $validated['answers'])
		);
		if ($accepted === null) {
			return new JSONResponse(['error' => 'not_accepted'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$mailedTo = $this->sendConfirmation(site: $site, render: $render, answers: $validated['answers'], reference: $accepted['reference']);

		return new JSONResponse([
			'reference' => $accepted['reference'],
			'state' => $accepted['state'],
			'confirmationText' => (string)($render['settings']['confirmationText'] ?? ''),
			'confirmation' => ($render['settings']['confirmation'] ?? null),
			'mailedTo' => $mailedTo,
		]);
	}//end submit()

	/**
	 * Decide one step at the step change: ask the rule engine on the server and
	 * answer with the outcome, the field it fills and the step it opens. The
	 * rule and its table never reach the browser.
	 *
	 * @param string $route The form page.
	 * @param string $step The id of the step that declares the decision.
	 * @param array<string, mixed> $answers The answers so far.
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{outcome, output, nextStep}`, 503 when the engine does not answer, or 404.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function decide(string $route, string $step, array $answers = [], string $portal = ''): JSONResponse {
		$site = $this->site(portal: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: $route);
		if ($binding === null) {
			return new JSONResponse(['error' => 'form_not_found'], Http::STATUS_NOT_FOUND);
		}

		$render  = $this->bindings->render(binding: $binding);
		$refusal = $this->signInRefusal(site: $site, binding: $binding, render: $render, subject: $this->subject());
		if ($refusal !== null) {
			return $refusal;
		}

		$declared = null;
		foreach ((array)($render['steps'] ?? []) as $candidate) {
			if (($candidate['id'] ?? null) === $step && is_array($candidate['decision'] ?? null) === true) {
				$declared = $candidate['decision'];
			}
		}

		if ($declared === null || $this->decision === null) {
			return new JSONResponse(['error' => 'step_not_found'], Http::STATUS_NOT_FOUND);
		}

		$names = array_map(static fn (array $field): string => (string)($field['name'] ?? ''), (array)($render['fields'] ?? []));
		$known = array_intersect_key($answers, array_flip($names));
		if ($this->calculator !== null) {
			$known = $this->calculator->apply(fields: (array)($render['fields'] ?? []), answers: $known)['answers'];
		}

		$decided = $this->decision->decide(decision: $declared, answers: $known);
		if ($decided['status'] === PortalFormDecision::UNAVAILABLE) {
			return new JSONResponse(['error' => 'decision_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['outcome' => $decided['outcome'], 'output' => $decided['output'], 'nextStep' => $decided['nextStep']]);
	}//end decide()

	/**
	 * The steps as the browser may see them: a decision is reduced to the fact
	 * that the step decides, so the rule, its inputs and its outcomes stay here.
	 *
	 * @param array<int, array<string, mixed>> $steps The form's steps.
	 *
	 * @return array<int, array<string, mixed>> The steps for the browser.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	private function stepsForTheBrowser(array $steps): array {
		foreach ($steps as $index => $step) {
			if (is_array($step) === true && array_key_exists('decision', $step) === true) {
				unset($steps[$index]['decision']);
				$steps[$index]['decides'] = true;
			}
		}

		return $steps;
	}//end stepsForTheBrowser()

	/**
	 * Work out the calculated fields and the decided ones for a submission.
	 *
	 * @param array<string, mixed> $render What render() returned for the form.
	 * @param array<string, mixed> $answers The validated answers.
	 *
	 * @return array{answers: array<string, mixed>, computed: array<int, string>, decisions: array<string, string>}|null
	 *         Null when a decision the form declares could not be asked.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	private function workedOut(array $render, array $answers): ?array {
		$fields   = (array)($render['fields'] ?? []);
		$computed = [];
		if ($this->calculator !== null) {
			$first    = $this->calculator->apply(fields: $fields, answers: $answers);
			$answers  = $first['answers'];
			$computed = $first['computed'];
		}

		$decisions = [];
		foreach ((array)($render['steps'] ?? []) as $step) {
			if (is_array($step['decision'] ?? null) === false) {
				continue;
			}

			if ($this->decision === null) {
				return null;
			}

			$decided = $this->decision->decide(decision: $step['decision'], answers: $answers);
			if ($decided['status'] === PortalFormDecision::UNAVAILABLE) {
				return null;
			}

			if ($decided['status'] === PortalFormDecision::DECIDED) {
				$answers[$decided['output']]   = $decided['outcome'];
				$decisions[(string)$step['id']] = $decided['outcome'];
				$computed[]                    = $decided['output'];
			}
		}//end foreach

		if ($decisions !== [] && $this->calculator !== null) {
			// A calculation may read a decided field.
			$again    = $this->calculator->apply(fields: $fields, answers: $answers);
			$answers  = $again['answers'];
			$computed = array_merge($computed, $again['computed']);
		}

		return ['answers' => $answers, 'computed' => array_values(array_unique($computed)), 'decisions' => $decisions];
	}//end workedOut()

	/**
	 * The resident's partner and children to choose from, DigiD only.
	 *
	 * Reads the BSN from the session's own account, never from the request.
	 * A visitor without a DigiD session, and a BRP that cannot be asked, get
	 * the same answer: nothing to choose from here.
	 *
	 * @param bool $sameAddressOnly Keep only members living at the resident's address.
	 *
	 * @return JSONResponse `{members: [...]}`, 401 without a session, 404 when nothing can be offered.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function family(bool $sameAddressOnly=true): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$members = $this->family?->forSubject(subjectRef: (string)($subject['subjectRef'] ?? ''), sameAddressOnly: $sameAddressOnly);
		if ($members === null) {
			return new JSONResponse(['error' => 'family_unavailable'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['members' => $members]);
	}//end family()

	/**
	 * The errors of `familyMembers` answers that are not the resident's family.
	 *
	 * @param array<int, array<string, mixed>> $fields  The form's fields.
	 * @param array<string, mixed>             $answers The validated answers.
	 * @param array<string, mixed>|null        $subject The session's subject.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	private function familyErrors(array $fields, array $answers, ?array $subject): array {
		$errors = [];
		foreach ($fields as $field) {
			$name = (string)($field['name'] ?? '');
			if (($field['type'] ?? '') !== 'familyMembers' || $name === '' || empty($answers[$name]) === true) {
				continue;
			}

			$refs   = (array)$answers[$name];
			$forged = [];
			if ($this->family === null || $subject === null) {
				$forged = $refs;
			} else {
				$forged = $this->family->forged(subjectRef: (string)($subject['subjectRef'] ?? ''), refs: $refs);
			}

			if ($forged !== []) {
				$errors[$name] = 'Choose the people from the list we found.';
			}
		}

		return $errors;
	}//end familyErrors()

	/**
	 * The statements this form asks, with the portal's wording.
	 *
	 * @param array<string, mixed> $render What the binding renders to.
	 * @param array<string, mixed> $site   The portal.
	 *
	 * @return array<int, array{key: string, required: bool, text: string, version: string}>
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	private function askedStatements(array $render, array $site): array {
		$declared = ($render['settings']['statementsDeclared'] ?? null);
		if ($declared === null) {
			return [];
		}

		if ($this->statements === null) {
			// A form that asks for statements this server cannot show is not sent.
			return array_map(
				static fn (string $key): array => ['key' => $key, 'required' => true, 'text' => '', 'version' => ''],
				array_keys(array_intersect_key((array)$declared, array_flip(FormStatements::KEYS)))
			);
		}

		return $this->statements->asked(declared: $declared, site: $site);
	}//end askedStatements()

	/**
	 * Mail the confirmation when the form asks for it, and record the outcome.
	 *
	 * @param array<string, mixed> $site      The portal.
	 * @param array<string, mixed> $render    What the binding renders to.
	 * @param array<string, mixed> $answers   The accepted answers.
	 * @param string               $reference The submission's reference.
	 *
	 * @return string The address the mail went to, or '' when none went.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	private function sendConfirmation(array $site, array $render, array $answers, string $reference): string {
		if (($render['settings']['confirmationMail'] ?? false) !== true || $this->confirmationMail === null) {
			return '';
		}

		$fields = (array)($render['fields'] ?? []);
		$email  = $this->confirmationMail->addressIn(fields: $fields, answers: $answers);
		if ($email === '') {
			return '';
		}

		$sent = $this->confirmationMail->send(
			email: $email,
			site: $site,
			reference: $reference,
			formName: (string)($render['formName'] ?? ''),
			summary: (new FormConfirmationSummary())->build(fields: $fields, answers: $answers)
		);
		$this->queue->markConfirmationMail(reference: $reference, portal: (string)($site['slug'] ?? ''), state: $this->mailState(sent: $sent));
		if ($sent === true) {
			return $email;
		}

		return '';
	}//end sendConfirmation()

	/**
	 * The state word recorded for a mail.
	 *
	 * @param bool $sent Whether the mail server took it.
	 *
	 * @return string
	 */
	private function mailState(bool $sent): string {
		if ($sent === true) {
			return 'sent';
		}

		return 'failed';
	}//end mailState()

	/**
	 * What became of a submission.
	 *
	 * @param string $reference The reference the citizen was given.
	 * @param string $portal The portal's slug, as the site renderer names it inside Nextcloud; empty resolves the portal from the host.
	 *
	 * @return JSONResponse The real state, including a create that failed.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function status(string $reference, string $portal = ''): JSONResponse {
		$site = $this->site(portal: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$status = $this->queue->status(reference: $reference, portal: (string)($site['slug'] ?? ''));
		if ($status === null) {
			return new JSONResponse(['error' => 'reference_not_found'], Http::STATUS_NOT_FOUND);
		}

		// The payment state is read from the payment record the portal stored
		// the id of, never from the address the resident returned on.
		$payment = $this->paymentOf(reference: $reference, portal: (string)($site['slug'] ?? ''));
		if ($payment !== null) {
			$status['payment'] = $payment;
		}

		return new JSONResponse($status);
	}//end status()

	/**
	 * Take the payment for a submission: forward the case app's pay action
	 * with an amount the portal built, and answer the checkout address.
	 *
	 * Order of refusals: 401 without a session, 404 unless the submission is
	 * the subject's own, 409 when the case type declares no fee or the
	 * request is paid, 403 when the declared pay action is not in the
	 * subject's own manifest, 502 for an answer without a usable checkout on
	 * a declared host. Nothing is forwarded on the first four.
	 *
	 * @param string $reference The submission's reference.
	 * @param string $portal    The portal's slug.
	 *
	 * @return JSONResponse `{checkoutUrl}` or the refusal.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function pay(string $reference, string $portal = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$site = $this->site(portal: $portal);
		if ($site === null || $this->fees === null || $this->forwarder === null || $this->registry === null) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$slug       = (string)($site['slug'] ?? '');
		$submission = $this->findSubmission(reference: $reference, portal: $slug);
		$owner = (string)($submission['subjectRef'] ?? '');
		if ($submission === null || $owner === '' || $owner !== (string)($subject['subjectRef'] ?? '')) {
			return new JSONResponse(['error' => 'reference_not_found'], Http::STATUS_NOT_FOUND);
		}

		$binding = $this->bindings->bindingFor(portal: $slug, route: (string)($submission['route'] ?? ''));
		$fee     = null;
		if ($binding !== null) {
			$fee = $this->fees->forBinding(binding: $binding);
		}

		$paid = (($this->paymentOf(reference: $reference, portal: $slug)['state'] ?? '') === 'paid');
		if ($fee === null || $paid === true) {
			return new JSONResponse(['error' => 'nothing_to_pay'], Http::STATUS_CONFLICT);
		}

		$action = $this->payAction(subject: $subject, actionId: $fee['payAction']);
		if ($action === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$response = $this->forwarder->forward(
			action: $action,
			subject: $subject,
			whitelisted: [
				'reference' => $reference,
				'amount' => $fee['amount'],
				'currency' => $fee['currency'],
				'description' => $fee['description'],
				'returnUrl' => $this->returnUrl(slug: $slug, route: (string)($submission['route'] ?? ''), reference: $reference),
			]
		);
		if ($response === null || $response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$answer   = $this->forwarder->decodeBody($response);
		$checkout = ($answer['checkoutUrl'] ?? null);
		$intentId = trim((string)($answer['paymentIntentId'] ?? ''));
		if ($intentId === '' || $this->fees->checkoutAllowed(url: $checkout, hosts: (array)($site['paymentHosts'] ?? [])) === false) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$this->queue->markPaymentIntent(submission: $submission, paymentIntentId: $intentId);

		return new JSONResponse(['checkoutUrl' => $checkout]);
	}//end pay()

	/**
	 * A submission by its reference, or null when it is not there or cannot be read.
	 *
	 * @param string $reference The submission's reference.
	 * @param string $portal    The portal's slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	private function findSubmission(string $reference, string $portal): ?array {
		try {
			return $this->queue->find(reference: $reference, portal: $portal);
		} catch (Throwable) {
			return null;
		}
	}//end findSubmission()

	/**
	 * The payment state of a submission, or null when none was started.
	 *
	 * @param string $reference The submission's reference.
	 * @param string $portal    The portal's slug.
	 *
	 * @return array{state: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	private function paymentOf(string $reference, string $portal): ?array {
		$submission = $this->findSubmission(reference: $reference, portal: $portal);
		$intentId   = trim((string)($submission['paymentIntentId'] ?? ''));
		if ($intentId === '' || $this->intents === null || $this->fees === null) {
			return null;
		}

		$status = $this->intents->status(id: $intentId);
		if ($status === null) {
			return ['state' => 'unknown'];
		}

		return ['state' => $this->fees->stateOf(status: $status)];
	}//end paymentOf()

	/**
	 * The case app's pay action in the subject's own manifest, or null.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $actionId The `payAction` the case type declares.
	 *
	 * @return array<string, mixed>|null
	 */
	private function payAction(array $subject, string $actionId): ?array {
		$aggregate = $this->registry?->aggregateFor($subject);
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === true && ($action['id'] ?? '') === $actionId && $this->forwarder?->isForwardable($action) === true) {
					return $action;
				}
			}
		}

		return null;
	}//end payAction()

	/**
	 * The page the resident comes back to from the payment page.
	 *
	 * @param string $slug      The portal.
	 * @param string $route     The form's route.
	 * @param string $reference The submission's reference.
	 *
	 * @return string
	 */
	private function returnUrl(string $slug, string $route, string $reference): string {
		$base = '';
		if ($this->deepLinks !== null) {
			$base = $this->deepLinks->forSite(portalSlug: $slug);
		}

		return $base . '&route=' . rawurlencode('/' . ltrim($route, '/')) . '&reference=' . rawurlencode($reference);
	}//end returnUrl()

	/**
	 * Why this form accepts no submission from this visitor, or null.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render What the binding renders to.
	 * @param array<string, mixed>|null $subject The subject, or null.
	 *
	 * @return JSONResponse|null
	 */
	private function submitRefusal(array $site, array $binding, array $render, ?array $subject): ?JSONResponse {
		if (($render['resolvesToNoForm'] ?? false) === true || ($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL) {
			// An external intake is filled in at its own host; the portal has
			// nothing to accept here.
			return new JSONResponse(['error' => 'form_not_submittable'], Http::STATUS_FORBIDDEN);
		}

		return $this->signInRefusal(site: $site, binding: $binding, render: $render, subject: $subject);
	}//end submitRefusal()

	/**
	 * The refusal for a visitor whose session does not meet the form's
	 * sign-in level, or null when the visitor may go on.
	 *
	 * The level is the strictest of the portal's, the binding's and the
	 * form's own (portaliq#725). No session is a 401 so the page can offer the
	 * sign-in; a session below the level is a 403.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render What the binding renders to.
	 * @param array<string, mixed>|null $subject The subject, or null.
	 *
	 * @return JSONResponse|null
	 */
	private function signInRefusal(array $site, array $binding, array $render, ?array $subject): ?JSONResponse {
		$required = $this->bindings->requiredTrust(site: $site, binding: $binding, render: $render);
		if ($required === null) {
			return null;
		}

		if ($subject === null) {
			return new JSONResponse(['error' => 'sign_in_required', 'minTrust' => $required, 'fee' => ($render['fee'] ?? null)], Http::STATUS_UNAUTHORIZED);
		}

		if (PortalSessionService::trustSatisfies(subjectTrust: ($subject['trust'] ?? ''), minTrust: $required) === false) {
			return new JSONResponse(['error' => 'sign_in_required', 'minTrust' => $required, 'fee' => ($render['fee'] ?? null)], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end signInRefusal()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()

	/**
	 * The verified addresses to record with the submission.
	 *
	 * @param array<string, mixed> $render The rendered form.
	 * @param array<string, mixed> $answers The accepted answers.
	 *
	 * @return array<int, array{address: string, verifiedAt: string}>
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	private function verifiedRecord(array $render, array $answers): array {
		if ($this->emailVerification === null) {
			return [];
		}

		return $this->emailVerification->record(fields: (array)($render['fields'] ?? []), answers: $answers, at: gmdate(DATE_ATOM));
	}//end verifiedRecord()

	/**
	 * The errors for e-mail fields that must be verified and are not.
	 *
	 * @param array<string, mixed> $render The rendered form.
	 * @param array<string, mixed> $answers The accepted answers.
	 * @param array<string, mixed> $proofs The proofs the browser sent, by address.
	 * @param array<string, mixed> $site The portal.
	 * @param string $route The form page.
	 *
	 * @return array<string, string> The errors by field name.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	private function unverifiedEmails(array $render, array $answers, array $proofs, array $site, string $route): array {
		if ($this->emailVerification === null) {
			return [];
		}

		return $this->emailVerification->unverified(
			fields: (array)($render['fields'] ?? []),
			answers: $answers,
			proofs: $proofs,
			portal: (string)($site['slug'] ?? ''),
			route: $route
		);
	}//end unverifiedEmails()

	/**
	 * The portal being visited, or null.
	 *
	 * @param string $portal The portal's slug, or empty for the host.
	 *
	 * @return array<string, mixed>|null
	 */
	private function site(string $portal = ''): ?array {
		if ($portal === '') {
			return $this->portals->resolve(request: $this->request, portalSlug: null);
		}

		return $this->portals->resolve(request: $this->request, portalSlug: $portal);
	}//end site()
}//end class
