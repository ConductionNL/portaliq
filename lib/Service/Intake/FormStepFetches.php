<?php

/**
 * Portaliq Form Step Fetches (data-lookups-and-checks-in-forms)
 *
 * The fetches a form's steps declare, as the browser sees them, as one step
 * runs them and as a submission takes their values again.
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\PortalFormCalculatorInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormDecisionInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormFetchInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The fetches a form's steps declare: the shape the browser may see, one
 * step's fetch run at the step change, and the fields a submission takes
 * from a fresh fetch. The source, its inputs and its raw answer stay here.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
final class FormStepFetches {
	/**
	 * Constructor.
	 *
	 * @param PortalFormFetchInterface|null      $fetches    Runs the fetches through integriq; null when none is wired.
	 * @param PortalFormCalculatorInterface|null $calculator Works out the calculated fields a fetch may read.
	 * @param PortalFormDecisionInterface|null   $decision   Asks the rule engine for the decided fields a fetch may read.
	 */
	public function __construct(
		private readonly ?PortalFormFetchInterface $fetches = null,
		private readonly ?PortalFormCalculatorInterface $calculator = null,
		private readonly ?PortalFormDecisionInterface $decision = null,
	) {
	}//end __construct()

	/**
	 * Run one fetch a step declares, at the step change, and answer with the
	 * fields it fills and its check line. The source, its inputs and its raw
	 * answer never reach the browser.
	 *
	 * @param array<string, mixed> $render  What render() returned for the form.
	 * @param string               $step    The id of the step that declares the fetch.
	 * @param string               $fetch   The id of the fetch.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return JSONResponse `{fetch, values, check, fetchedAt}`, 503 when the fetch does not answer, or 404.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function answerForStep(array $render, string $step, string $fetch, array $answers): JSONResponse {
		$declared = $this->fetches?->declared(steps: (array)($render['steps'] ?? []), step: $step, fetch: $fetch);
		if ($declared === null || $this->fetches === null) {
			return new JSONResponse(['error' => 'fetch_not_found'], Http::STATUS_NOT_FOUND);
		}

		$decisions = new SubmissionDecisions(calculator: $this->calculator, decision: $this->decision);
		$known     = $decisions->knownAnswers(fields: (array)($render['fields'] ?? []), answers: $answers);
		$answer = $this->fetches->run(fetch: $declared, answers: $known);
		if ($answer['status'] !== PortalFormFetch::FETCHED) {
			return new JSONResponse(['error' => 'fetch_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(
			[
				'fetch' => $fetch,
				'values' => ($answer['values'] ?? []),
				'check' => ($answer['check'] ?? null),
				'fetchedAt' => ($answer['fetchedAt'] ?? ''),
			]
		);
	}//end answerForStep()

	/**
	 * The answers with every field a step's fetch fills taken from a fresh
	 * fetch, so a value the browser changed never reaches a case; the refusal
	 * when a fetch does not answer (data-lookups-and-checks-in-forms REQ-DIF-005).
	 *
	 * @param array<int, array<string, mixed>> $steps   The form's steps.
	 * @param array<string, mixed>             $answers The validated answers.
	 *
	 * @return array<string, mixed>|JSONResponse
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function filledAnswers(array $steps, array $answers): array|JSONResponse {
		if ($this->declaresFetch(steps: $steps) === false) {
			return $answers;
		}

		$filled = $this->fetches?->filled(steps: $steps, answers: $answers);
		if ($filled === null) {
			return new JSONResponse(['error' => 'fetch_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return $filled;
	}//end filledAnswers()

	/**
	 * A step's fetches as the browser may see them: the id, the fields each
	 * fills and its check label. The source and the inputs stay here.
	 *
	 * @param array<int, mixed> $fetches The step's fetches.
	 *
	 * @return array<int, array{id: string, fields: array<int, string>, checkLabel: string|null}>
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function forTheBrowser(array $fetches): array {
		$visible = [];
		foreach ($fetches as $fetch) {
			if (is_array($fetch) === false) {
				continue;
			}

			$checkLabel = null;
			if (is_array(($fetch['check'] ?? null)) === true) {
				$checkLabel = (string)($fetch['check']['label'] ?? '');
			}

			$visible[] = [
				'id' => (string)($fetch['id'] ?? ''),
				'fields' => array_values((array)($fetch['outputs'] ?? [])),
				'checkLabel' => $checkLabel,
			];
		}

		return $visible;
	}//end forTheBrowser()

	/**
	 * Whether any step declares a fetch.
	 *
	 * @param array<int, array<string, mixed>> $steps The form's steps.
	 *
	 * @return bool
	 */
	private function declaresFetch(array $steps): bool {
		foreach ($steps as $step) {
			if (is_array($step) === true && empty($step['fetch']) === false) {
				return true;
			}
		}

		return false;
	}//end declaresFetch()
}//end class
