<?php

/**
 * OnCaseCreate: the case a create action filed from a case page is about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * A create request that names `onCase=<register>/<schema>/<id>` is about that case. The case is
 * re-read through the action's own cross-reference scope, the window is reckoned again, and the
 * case id is the value of the cross-reference field, over anything the body sent. The
 * cross-reference guard still runs on the result.
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */
class OnCaseCreate {

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReaderInterface $reader  Subject-scoped OpenRegister reader.
	 * @param CaseActionsResolver         $actions The placement and the window.
	 *
	 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
	 */
	public function __construct(
		private readonly PortalObjectReaderInterface $reader,
		private readonly CaseActionsResolver $actions,
	) {
	}//end __construct()

	/**
	 * The fields the server sets for a create filed from a case page, or the refusal.
	 *
	 * @param string              $target  The `onCase` parameter, `<register>/<schema>/<id>`.
	 * @param array<string,mixed> $action  The matched create action.
	 * @param array<string,mixed> $subject The resolved subject.
	 * @param string              $app     The contributing app.
	 *
	 * @return array<string,string>|JSONResponse The cross-reference field and the case id.
	 *
	 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
	 */
	public function carried(string $target, array $action, array $subject, string $app): array|JSONResponse {
		$parts = explode('/', $target);
		if (count($parts) !== 3 || in_array('', $parts, true) === true) {
			return new JSONResponse(['error' => 'on_case_invalid'], Http::STATUS_BAD_REQUEST);
		}

		[$register, $schema, $id] = $parts;
		$field = $this->actions->crossRefField(action: $action, register: $register, schema: $schema);
		if ($field === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$case = $this->ownCase(reference: (array)$action['crossRefs'][$field], id: $id, subject: $subject, app: $app);
		if ($case === null) {
			return new JSONResponse(['error' => 'cross_ref_refused', 'field' => $field], Http::STATUS_FORBIDDEN);
		}

		if ($this->actions->window(action: $action, case: $case)['open'] === false) {
			return new JSONResponse(
				['error' => 'case_action_closed', 'message' => $this->actions->closedText(action: $action)],
				Http::STATUS_CONFLICT
			);
		}

		return [$field => $id];
	}//end carried()

	/**
	 * The case read through the cross-reference's own scope, or null when it is not the subject's.
	 *
	 * @param array<string,mixed> $reference The cross-reference declaration.
	 * @param string              $id        The case id.
	 * @param array<string,mixed> $subject   The resolved subject.
	 * @param string              $app       The contributing app.
	 *
	 * @return array<string,mixed>|null The case.
	 */
	private function ownCase(array $reference, string $id, array $subject, string $app): ?array {
		return $this->reader->readObject(
			register: (string)($reference['register'] ?? ''),
			schema: (string)($reference['schema'] ?? ''),
			scopeField: (string)($reference['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($reference['scopeClaim'] ?? ''),
			contributingApp: $app,
			audience: (string)($subject['audience'] ?? '')
		);
	}//end ownCase()
}//end class
