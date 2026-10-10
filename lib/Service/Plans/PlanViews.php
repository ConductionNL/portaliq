<?php

/**
 * Portaliq Plan Views (shared-plans-with-a-caseworker)
 *
 * A plan as the screen shows it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;

/**
 * The card of a plan, its note, its actions and the names of the people in it.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PlanViews {
	/**
	 * Constructor.
	 *
	 * @param PortalAccountLookup $accounts Reads an account's name.
	 * @param PlanRules           $rules    The plan rules.
	 */
	public function __construct(
		private readonly PortalAccountLookup $accounts,
		private readonly PlanRules $rules = new PlanRules(),
	) {
	}//end __construct()

	/**
	 * The card of a plan: the numbers and the words of the list and the page.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 * @param array<int, string> $participants The participants' subject references.
	 * @param array<int, array<string, mixed>> $actions Its actions.
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function card(array $plan, array $participants, array $actions, array $subject, string $today): array {
		$done  = count(array_filter($actions, static fn (array $action): bool => ($action['status'] ?? '') === 'done'));
		$open  = (count($actions) - $done);
		$owner = (string)($plan['owner'] ?? '');
		$role  = 'participant';
		if ($owner === (string)($subject['subjectRef'] ?? '')) {
			$role = 'owner';
		}

		$end      = substr((string)($plan['endDate'] ?? ''), 0, 10);
		$sharedBy = '';
		if ($role !== 'owner') {
			$sharedBy = $this->nameOf(ref: $owner);
		}

		$names = [];
		foreach (array_merge([$owner], $participants) as $ref) {
			$names[] = ['ref' => $ref, 'displayName' => $this->nameOf(ref: $ref), 'isOwner' => ($ref === $owner)];
		}

		return [
			'id'            => $this->rules->idOf(row: $plan),
			'title'         => (string)($plan['title'] ?? ''),
			'goal'          => (string)($plan['goal'] ?? ''),
			'endDate'       => $end,
			'daysLeft'      => $this->rules->daysLeft(endDate: $end, today: $today),
			'state'         => $this->rules->stateOf(plan: $plan, openActions: $open, today: $today),
			'role'          => $role,
			'sharedBy'      => $sharedBy,
			'openActions'   => $open,
			'doneActions'   => $done,
			'totalActions'  => count($actions),
			'participants'  => $names,
		];
	}//end card()

	/**
	 * The note of a plan with its last editor's name.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 *
	 * @return array{text: string, editedBy: string, editedAt: string}
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function note(array $plan): array {
		$note = $plan['note'] ?? [];
		if (is_array($note) === false) {
			$note = [];
		}

		$editor = (string)($note['editedBy'] ?? '');
		if ($editor !== '') {
			$editor = $this->nameOf(ref: $editor);
		}


		return [
			'text'     => (string)($note['text'] ?? ''),
			'editedBy' => $editor,
			'editedAt' => (string)($note['editedAt'] ?? ''),
		];
	}//end note()

	/**
	 * An action as the page shows it.
	 *
	 * @param array<string, mixed> $action The action row.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function action(array $action): array {
		return [
			'id'          => $this->rules->idOf(row: $action),
			'title'       => (string)($action['title'] ?? ''),
			'description' => (string)($action['description'] ?? ''),
			'kind'        => (string)($action['kind'] ?? 'once'),
			'status'      => (string)($action['status'] ?? 'todo'),
			'endDate'     => substr((string)($action['endDate'] ?? ''), 0, 10),
			'assignee'    => (string)($action['assignee'] ?? ''),
			'assigneeName' => $this->nameOf(ref: (string)($action['assignee'] ?? '')),
		];
	}//end action()

	/**
	 * A person's name, or '' when the account is unknown.
	 *
	 * @param string $ref The subject reference.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function nameOf(string $ref): string {
		if ($ref === '') {
			return '';
		}

		$account = $this->accounts->bySubjectRef(subjectRef: $ref);

		return (string)($account['displayName'] ?? '');
	}//end nameOf()
}//end class
