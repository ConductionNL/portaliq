<?php

/**
 * Portaliq Action Access
 *
 * What a resident may do with one action.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Actions
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
 * @spec openspec/changes/personal-action-list/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Actions;

use OCA\Portaliq\Service\Identity\PortalContactService;

/**
 * In a plan the viewer is a participant and writes through the plan; else
 * the owner writes through `owner` and an assignee through `assignee`. The
 * owner gives an action to themself or an approved contact, a participant
 * to anyone in the plan, and an assignee to no one else.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
 */
class ActionAccess {

	/**
	 * Constructor.
	 *
	 * @param PortalContactService $contacts The owner's approved contacts.
	 */
	public function __construct(
		private readonly PortalContactService $contacts,
	) {
	}//end __construct()

	/**
	 * What the viewer may do with one action, or null when they may not see it.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $row The action.
	 * @param array{plan: array<string, mixed>, isOwner: bool}|null $membership The viewer's place in the action's plan.
	 *
	 * @return array<string, mixed>|null `role` (owner, assignee or member), `scopeField` and `scopeValue` to write
	 *                                    through, `members` who may be assigned, `mayDelete`, `readOnly` and `planTitle`.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function forAction(array $subject, array $row, ?array $membership): ?array {
		if ($membership !== null) {
			return $this->asMember(row: $row, membership: ['ownsAction' => $this->owns(subject: $subject, row: $row)] + $membership);
		}

		return $this->personal(subject: $subject, row: $row);
	}//end forAction()

	/**
	 * Whether the viewer owns the action.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $row     The action.
	 *
	 * @return bool
	 */
	private function owns(array $subject, array $row): bool {
		$ref = (string)($subject['subjectRef'] ?? '');

		return $ref !== '' && (string)($row['owner'] ?? '') === $ref;
	}//end owns()

	/**
	 * What a member of the action's plan may do: write through the plan,
	 * assign any member, delete when they own the action or the plan.
	 *
	 * @param array<string, mixed>                                                $row        The action.
	 * @param array{ownsAction: bool, plan: array<string, mixed>, isOwner: bool} $membership The viewer's place in the plan.
	 *
	 * @return array<string, mixed>
	 */
	private function asMember(array $row, array $membership): array {
		$plan = $membership['plan'];

		return [
			'role'       => 'member',
			'scopeField' => 'plan',
			'scopeValue' => (string)($row['plan'] ?? ''),
			'members'    => $this->planMembers(plan: $plan),
			'mayDelete'  => ($membership['ownsAction'] === true || $membership['isOwner'] === true),
			'readOnly'   => (($plan['status'] ?? 'running') === 'done'),
			'planTitle'  => (string)($plan['title'] ?? ''),
		];
	}//end asMember()

	/**
	 * A plan's owner and participants, the ones that are text.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, string>
	 */
	private function planMembers(array $plan): array {
		$members = array_merge([(string)($plan['owner'] ?? '')], (array)($plan['participants'] ?? []));

		return array_values(array_filter($members, 'is_string'));
	}//end planMembers()

	/**
	 * What the owner or the assignee of a personal action may do, or null for anybody else.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $row     The action.
	 *
	 * @return array<string, mixed>|null
	 */
	private function personal(array $subject, array $row): ?array {
		$ref      = (string)($subject['subjectRef'] ?? '');
		$personal = ['scopeValue' => $ref, 'readOnly' => false, 'planTitle' => ''];
		if ($this->owns(subject: $subject, row: $row) === true) {
			return ['role' => 'owner', 'scopeField' => 'owner', 'members' => $this->ownMembers(subject: $subject), 'mayDelete' => true] + $personal;
		}

		if ($this->isAssignee(ref: $ref, row: $row) === true) {
			return ['role' => 'assignee', 'scopeField' => 'assignee', 'members' => [$ref], 'mayDelete' => false] + $personal;
		}

		return null;
	}//end personal()

	/**
	 * Whether the viewer is the action's assignee.
	 *
	 * @param string               $ref The viewer's subject reference.
	 * @param array<string, mixed> $row The action.
	 *
	 * @return bool
	 */
	private function isAssignee(string $ref, array $row): bool {
		return $ref !== '' && (string)($row['assignee'] ?? '') === $ref;
	}//end isAssignee()

	/**
	 * Who a resident's own action can be given to: the resident and their approved contacts.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return array<int, string> The subject references.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function ownMembers(array $subject): array {
		$ref = (string)($subject['subjectRef'] ?? '');
		if ($ref === '') {
			return [];
		}

		$refs = array_column($this->contacts->approvedContacts(owner: $ref, organisation: (string)($subject['organisation'] ?? '')), 'ref');

		return array_values(array_unique(array_merge([$ref], array_filter($refs, 'is_string'))));
	}//end ownMembers()
}//end class
