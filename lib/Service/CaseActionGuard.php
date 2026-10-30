<?php

/**
 * Portaliq Case Action Guard
 *
 * The server half of a create sent from a case page (`?onCase=`): it reads
 * the case again through the scoped reader, asks the window again, and hands
 * back the case id to write into the action's cross-reference field over
 * whatever the body says. The cross-reference guard still runs afterwards in
 * the create pipeline; this class never stands in for it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCA\Portaliq\Contribution\OnCaseConfigNormaliser;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Admits a create sent from a case page, or answers the refusal.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */
class CaseActionGuard {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader  $reader  The scoped read that answers whose a case is.
	 * @param CaseActionsResolver $actions The placement and window rules.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly CaseActionsResolver $actions = new CaseActionsResolver(),
	) {
	}//end __construct()

	/**
	 * The fields to set over the body, `[crossRef => caseId]`, or the refusal.
	 *
	 * @param array<string, mixed>   $action  The matched, normalised create action.
	 * @param string                 $onCase  The `<register>/<schema>/<id>` the page sent.
	 * @param array<string, mixed>   $subject The resolved subject.
	 * @param string                 $app     The contributing app.
	 * @param DateTimeImmutable|null $now     The moment to judge the window at; now by default.
	 *
	 * @return array<string, string>|JSONResponse
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t05
	 */
	public function carried(array $action, string $onCase, array $subject, string $app, ?DateTimeImmutable $now=null): array|JSONResponse {
		$parts = explode('/', $onCase);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return new JSONResponse(['error' => 'on_case_invalid'], Http::STATUS_BAD_REQUEST);
		}

		[$register, $schema, $id] = $parts;
		$crossRef = (string)($action[OnCaseConfigNormaliser::KEY]['crossRef'] ?? '');
		if ($this->actions->placedOn(action: $action, register: $register, schema: $schema) === false) {
			// The action is not offered on a case there: the page cannot
			// name a collection of its own choosing.
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$reference = (array)$this->actions->reference(action: $action);
		$case      = $this->reader->readObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($reference['scopeField'] ?? ''),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($reference['scopeClaim'] ?? ''),
			contributingApp: $app,
			via: null,
			audience: (string)($subject['audience'] ?? '')
		);
		if ($case === null) {
			// Someone else's case and a missing one answer as the guard does.
			return new JSONResponse(['error' => 'cross_ref_refused', 'field' => $crossRef], Http::STATUS_FORBIDDEN);
		}

		if ($this->actions->window(action: $action, case: $case, now: $now)['open'] === false) {
			return new JSONResponse(
				['error' => 'case_action_closed', 'message' => $this->actions->closedText(action: $action)],
				Http::STATUS_CONFLICT
			);
		}

		return [$crossRef => $id];
	}//end carried()
}//end class
