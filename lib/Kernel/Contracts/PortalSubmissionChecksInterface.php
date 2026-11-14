<?php

/**
 * Contract of PortalSubmissionChecks
 *
 * The public surface of PortalSubmissionChecks that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of PortalSubmissionChecks.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSubmissionChecksInterface {

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
	public function askedStatements(array $render, array $site): array;

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
	public function decideStep(array $render, string $step, array $answers): JSONResponse;

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
	 *     verified: array<int, array{address: string, verifiedAt: string}>
	 * }|JSONResponse The checked submission, or the refusal.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	public function prepare(
		array $render,
		array $site,
		string $route,
		array $answers,
		?array $subject,
		array $statements,
		array $verifiedEmails
	): array|JSONResponse;

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
	public function stepsForTheBrowser(array $steps): array;
}//end interface
