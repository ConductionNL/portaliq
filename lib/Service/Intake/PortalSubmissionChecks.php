<?php

/**
 * Portaliq Submission Checks (portal-intake-form-as-an-object)
 *
 * Checks a submission before anything is recorded.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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

namespace OCA\Portaliq\Service\Intake;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The checks between a validated form and a recorded submission: validation,
 * family and e-mail proofs, calculations and decisions, and statements.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalSubmissionChecks {
	/**
	 * Constructor.
	 *
	 * @param PortalFormValidator $validator Validates a submission.
	 * @param PortalFamilyMembers|null $family Lists and re-checks the resident's family from the BRP.
	 * @param FormStatements|null $statements Resolves and checks the statements a form asks.
	 * @param PortalFormCalculator|null $calculator Works out the form's calculated fields again on submit.
	 * @param PortalFormDecision|null $decision Asks the rule engine for the decisions a form's steps declare.
	 * @param PortalEmailVerification|null $emailVerification Checks the proof that an e-mail address was verified.
	 */
	public function __construct(
		private readonly PortalFormValidator $validator,
		private readonly ?PortalFamilyMembers $family = null,
		private readonly ?FormStatements $statements = null,
		private readonly ?PortalFormCalculator $calculator = null,
		private readonly ?PortalFormDecision $decision = null,
		private readonly ?PortalEmailVerification $emailVerification = null,
	) {
	}//end __construct()

	/**
	 * Check a submission; the checked answers and what to record, or the refusal.
	 *
	 * @param array<string, mixed>      $render         What render() returned for the form.
	 * @param array<string, mixed>      $site           The resolved portal.
	 * @param string                    $route          The form page submitted.
	 * @param array<string, mixed>      $answers        What the citizen answered.
	 * @param array<string, mixed>|null $subject        The session's subject.
	 * @param array<int, string>        $statements     The keys of the statements the citizen ticked.
	 * @param array<string, string>     $verifiedEmails Proofs of verified e-mail addresses, by address.
	 *
	 * @return array{
	 *     answers: array<string, mixed>,
	 *     computed: array<int, string>,
	 *     decisions: array<string, string>,
	 *     statements: array<int, array<string, string>>,
	 *     verified: array<string, mixed>
	 * }|JSONResponse The checked submission, or the refusal.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function prepare(
		array $render,
		array $site,
		string $route,
		array $answers,
		?array $subject,
		array $statements,
		array $verifiedEmails
	): array|JSONResponse {
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

		$checked = $this->checkedStatements(render: $render, site: $site, accepted: $statements);
		if ($checked instanceof JSONResponse) {
			return $checked;
		}

		return [
			'answers' => $worked['answers'],
			'computed' => $worked['computed'],
			'decisions' => $worked['decisions'],
			'statements' => $checked,
			'verified' => $this->verifiedRecord(render: $render, answers: $worked['answers']),
		];
	}//end prepare()

	/**
	 * Decide one step at the step change: ask the rule engine on the server.
	 *
	 * @param array<string, mixed> $render  What render() returned for the form.
	 * @param string               $step    The id of the step that declares the decision.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return JSONResponse `{outcome, output, nextStep}`, 503 when the engine does not answer, or 404.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function decideStep(array $render, string $step, array $answers): JSONResponse {
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
	}//end decideStep()

	/**
	 * The statements the form asks are accepted before anything is recorded;
	 * the record of each accepted one carries the version of its text
	 * (form-statements-intro-and-confirmation-mail REQ-FCI-002).
	 *
	 * @param array<string, mixed> $render   What render() returned for the form.
	 * @param array<string, mixed> $site     The resolved portal.
	 * @param array<int, string>   $accepted The keys the citizen ticked.
	 *
	 * @return array<int, array<string, string>>|JSONResponse What to record, or the refusal.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	private function checkedStatements(array $render, array $site, array $accepted): array|JSONResponse {
		$asked = $this->askedStatements(render: $render, site: $site);
		if ($asked === []) {
			return [];
		}

		if ($this->statements === null) {
			return new JSONResponse(['error' => 'statements_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$checked = $this->statements->check(asked: $asked, accepted: $accepted);
		if ($checked['errors'] === []) {
			return $checked['record'];
		}

		$errors = [];
		foreach ($checked['errors'] as $key => $message) {
			$errors['statement-' . $key] = $message;
		}

		return new JSONResponse(['errors' => $errors], Http::STATUS_BAD_REQUEST);
	}//end checkedStatements()

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
	public function stepsForTheBrowser(array $steps): array {
		foreach ($steps as $index => $step) {
			if (is_array($step) === true && array_key_exists('decision', $step) === true) {
				unset($steps[$index]['decision']);
				$steps[$index]['decides'] = true;
			}
		}

		return $steps;
	}//end stepsForTheBrowser()

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
	public function askedStatements(array $render, array $site): array {
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
			$forged = $refs;
			if ($this->family !== null && $subject !== null) {
				$forged = $this->family->forged(subjectRef: (string)($subject['subjectRef'] ?? ''), refs: $refs);
			}

			if ($forged !== []) {
				$errors[$name] = 'Choose the people from the list we found.';
			}
		}

		return $errors;
	}//end familyErrors()

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

		return $this->emailVerification->record(fields: (array)($render['fields'] ?? []), answers: $answers, verifiedAt: gmdate(DATE_ATOM));
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
}//end class
