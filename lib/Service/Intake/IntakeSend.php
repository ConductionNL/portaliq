<?php

/**
 * Portaliq Intake Send
 *
 * The last step of a submission, shared by the resident's own submit and an
 * employee's submit for a resident: hand the checked answers to the
 * binding's destination, mail the confirmation and answer the reference.
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Delivers a prepared submission and shapes the answer the page reads.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
 */
class IntakeSend {
	/**
	 * Constructor.
	 *
	 * @param DestinationIntake|null   $intake       Submits the answers into the binding's destination (decision 179).
	 * @param PortalIntakeConfirmation $confirmation Mails the resident the confirmation.
	 */
	public function __construct(
		private readonly ?DestinationIntake $intake,
		private readonly PortalIntakeConfirmation $confirmation,
	) {
	}//end __construct()

	/**
	 * Deliver the prepared answers and answer the reference, or the refusal.
	 *
	 * @param array<string, mixed> $site       The portal.
	 * @param array<string, mixed> $binding    The binding.
	 * @param array<string, mixed> $render     What the binding renders to.
	 * @param array<string, mixed> $prepared   What PortalSubmissionChecks::prepare() answered.
	 * @param string               $subjectRef The subject the request is filed under, or ''.
	 * @param array<string, mixed> $request    `origin`, `idempotencyKey` and `server` (extra server values, such as who filled the form in).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
	 */
	public function send(array $site, array $binding, array $render, array $prepared, string $subjectRef, array $request): JSONResponse {
		$server = array_merge(
			[
				'portal' => (string)($site['slug'] ?? ''),
				'origin' => (string)($request['origin'] ?? ''),
				'statements' => $prepared['statements'],
				'decisions' => $prepared['decisions'],
				'verifiedEmails' => $prepared['verified'],
				'fieldMeta' => (new DestinationMapping())->fieldMeta(computed: $prepared['computed']),
			],
			(array)($request['server'] ?? [])
		);

		$submitted = $this->intake?->submit(
			binding: $binding,
			render: $render,
			answers: $prepared['answers'],
			subjectRef: $subjectRef,
			server: $server,
			idempotencyKey: (string)($request['idempotencyKey'] ?? '')
		);
		if ($submitted === null || $submitted['ok'] === false) {
			$refusal = DestinationIntake::refusal(submitted: $submitted);
			return new JSONResponse($refusal['body'], $refusal['status']);
		}

		$answer    = $submitted['answer'];
		$reference = (string)($answer['reference'] ?? '');
		$moments   = (array)($answer['confirmation'] ?? []);
		$mailedTo  = $this->confirmation->send(site: $site, render: $render, answers: $prepared['answers'], reference: $reference, moments: $moments);

		return new JSONResponse(
			array_merge(
				$moments,
				[
					'reference' => $reference,
					'id' => (string)($answer['id'] ?? ''),
					'receivedAt' => (string)($answer['receivedAt'] ?? ''),
					'state' => PortalIntakeQueue::STATE_REGISTERED,
					'confirmationText' => (string)($render['settings']['confirmationText'] ?? ''),
					'confirmation' => ($render['settings']['confirmation'] ?? null),
					'mailedTo' => $mailedTo,
				]
			)
		);
	}//end send()
}//end class
