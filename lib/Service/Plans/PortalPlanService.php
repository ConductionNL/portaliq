<?php

/**
 * Portaliq Portal Plan Service
 *
 * The plans a resident works on with their contacts: the list, one plan,
 * starting from a template, and what each person may change.
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IL10N;

/**
 * Every participant changes the goal, the actions and the note; only the owner
 * changes who takes part, the end date and the title, marks the plan done or
 * deletes it. A resident reads and writes only a plan they own or take part
 * in, and an unknown id and someone else's id answer the same.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- reader, writer, contacts, accounts and rules.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- one method per thing a resident does with a plan.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the permission rules of one plan live together.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PortalPlanService {

	public const REGISTER = 'portaliq';

	public const OK = 'ok';

	public const NOT_FOUND = 'not_found';

	public const FORBIDDEN = 'forbidden';

	public const INVALID = 'invalid';

	public const FAILED = 'failed';

	/**
	 * The statuses an action may have.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['todo', 'doing', 'done'];

	/**
	 * The published plan templates of a portal.
	 *
	 * @var PlanTemplates
	 */
	private readonly PlanTemplates $templates;

	/**
	 * A plan as the screen shows it.
	 *
	 * @var PlanViews
	 */
	private readonly PlanViews $views;

	/**
	 * Checks one requested change to a plan.
	 *
	 * @var PlanFieldChanges
	 */
	private readonly PlanFieldChanges $changes;

	/**
	 * Holds what was sent for an action to the fields a participant may write.
	 *
	 * @var PlanActionFields
	 */
	private readonly PlanActionFields $actionFields;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads plans, templates and actions.
	 * @param PortalObjectWriter $writer Writes plans and actions.
	 * @param PortalContactService $contacts The approved contacts of the owner.
	 * @param PortalAccountLookup $accounts Reads a participant's name.
	 * @param IL10N $l10n The words of the PDF.
	 * @param PlanRules $rules The plan rules: dates, states and row ids.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalContactService $contacts,
		PortalAccountLookup $accounts,
		private readonly IL10N $l10n,
		private readonly PlanRules $rules = new PlanRules(),
	) {
		$this->templates    = new PlanTemplates(reader: $reader, rules: $rules);
		$this->views        = new PlanViews(accounts: $accounts, rules: $rules);
		$this->changes      = new PlanFieldChanges(rules: $rules);
		$this->actionFields = new PlanActionFields();
	}//end __construct()

	/**
	 * The templates a resident may start from: the published ones of the portal.
	 *
	 * @param string $portal The portal's slug.
	 *
	 * @return array<int, array<string, mixed>> `{id, title, summary, durationDays, actionCount}` each.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public function templates(string $portal): array {
		$out = [];
		foreach ($this->templates->published(portal: $portal) as $row) {
			$out[] = [
				'id'          => $this->idOf(row: $row),
				'title'       => (string)($row['title'] ?? ''),
				'summary'     => (string)($row['summary'] ?? ''),
				'durationDays' => (int)($row['durationDays'] ?? 0),
				'actionCount' => count($this->rules->expand(template: $row, today: '2000-01-01')['actions']),
			];
		}

		return $out;
	}//end templates()

	/**
	 * The resident's plans with counts and a card each.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array{plans: array<int, array<string, mixed>>, counts: array<string, int>} The cards, and the plans running, needing action and done.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public function overview(array $subject, string $today): array {
		$cards  = [];
		$counts = ['running' => 0, 'action' => 0, 'done' => 0];
		foreach ($this->plansOf(subject: $subject) as $plan) {
			if ($this->rules->visible(plan: $plan, today: $today) === false) {
				continue;
			}

			$actions = $this->actionsOf(planId: $this->idOf(row: $plan), organisation: (string)($subject['organisation'] ?? ''));
			$card    = $this->views->card(plan: $plan, participants: $this->participantsOf(plan: $plan), actions: $actions, subject: $subject, today: $today);
			$counts[$card['state']]++;
			$cards[] = $card;
		}

		// Plans that are going come first, the soonest end date first; done plans last.
		$key = static fn (array $card): array => [$card['state'] === 'done', $card['endDate'] === '', $card['endDate']];
		usort($cards, static fn (array $a, array $b): int => $key($a) <=> $key($b));

		return ['plans' => $cards, 'counts' => $counts];
	}//end overview()

	/**
	 * One plan with its actions and what the viewer may do.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array<string, mixed>|null The plan, or null for one the viewer is not part of.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	public function detail(array $subject, string $id, string $today): ?array {
		$plan = $this->planFor(subject: $subject, id: $id);
		if ($plan === null) {
			return null;
		}

		$actions = $this->actionsOf(planId: $id, organisation: (string)($subject['organisation'] ?? ''));
		$view    = $this->views->card(plan: $plan, participants: $this->participantsOf(plan: $plan), actions: $actions, subject: $subject, today: $today);
		$view['goalDetail'] = (string)($plan['goalDetail'] ?? '');
		$view['note']       = $this->views->note(plan: $plan);
		$view['actions']    = array_map(fn (array $action): array => $this->views->action(action: $action), $actions);
		$view['canEdit']    = ($view['state'] !== 'done');
		$view['isOwner']    = ($view['role'] === 'owner');
		if ($view['isOwner'] === true) {
			$view['contacts'] = $this->contacts->approvedContacts(owner: $this->refOf(subject: $subject), organisation: $this->orgOf(subject: $subject));
		}

		return $view;
	}//end detail()

	/**
	 * Start a plan, empty or from a template, with some of the owner's approved contacts.
	 *
	 * @param array<string, mixed> $subject The session subject, who becomes the owner.
	 * @param string $portal The portal's slug, for the templates.
	 * @param string $templateId A published template, or '' for an empty plan.
	 * @param string $title The name; the template's when empty.
	 * @param array<int, string> $contactIds The rows of approved contacts to add.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array{status: string, id: string} FORBIDDEN names a participant who is no approved contact.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public function start(array $subject, string $portal, string $templateId, string $title, array $contactIds, string $today): array {
		$owner        = (string)($subject['subjectRef'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		$participants = $this->participantRefs(owner: $owner, organisation: $organisation, contactIds: $contactIds);
		if ($participants === null) {
			return ['status' => self::FORBIDDEN, 'id' => ''];
		}

		$template = null;
		if ($templateId !== '') {
			$template = $this->templates->byId(portal: $portal, id: $templateId);
			if ($template === null) {
				return ['status' => self::INVALID, 'id' => ''];
			}
		}

		$title = $this->templates->startTitle(template: $template, title: $title);
		if ($title === '' || $owner === '') {
			return ['status' => self::INVALID, 'id' => ''];
		}

		$plan = ['owner' => $owner, 'participants' => $participants, 'title' => $title, 'status' => 'running', 'template' => $templateId];
		$expanded = ['endDate' => '', 'actions' => []];
		if ($template !== null) {
			$expanded = $this->rules->expand(template: $template, today: $today);
			$plan['goal']       = (string)($template['goal'] ?? '');
			$plan['goalDetail'] = (string)($template['goalDetail'] ?? '');
			$plan['endDate']    = $expanded['endDate'];
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: 'portalPlan',
			scopeField: 'owner',
			subjectRef: $owner,
			organisation: $organisation,
			data: $plan
		);
		if ($created === null) {
			return ['status' => self::FAILED, 'id' => ''];
		}

		$id = $this->idOf(row: $created);
		foreach ($expanded['actions'] as $action) {
			$this->createAction(subject: $subject, planId: $id, data: $action + ['assignee' => $owner]);
		}

		return ['status' => self::OK, 'id' => $id];
	}//end start()

	/**
	 * Change a plan. A participant changes the goal, its explanation and the
	 * note; the owner also the title, the end date and whether it is done.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<string, mixed> $changes What to change.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (an owner-only field, or a done plan), INVALID or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function update(array $subject, string $id, array $changes, string $today): string {
		$plan = $this->planFor(subject: $subject, id: $id);
		if ($plan === null) {
			return self::NOT_FOUND;
		}

		$isOwner = $this->isOwner(plan: $plan, subject: $subject);
		if (($plan['status'] ?? 'running') === 'done') {
			return self::FORBIDDEN;
		}

		$data = [];
		foreach ($changes as $field => $value) {
			$editor = $this->refOf(subject: $subject);
			$result = $this->changes->change(plan: $plan, editor: $editor, isOwner: $isOwner, field: (string)$field, value: $value, today: $today);
			if ($result['status'] !== self::OK) {
				return $result['status'];
			}

			$data = array_merge($data, $result['data']);
		}

		if ($data === []) {
			return self::INVALID;
		}

		return $this->write(subject: $subject, plan: $plan, isOwner: $isOwner, data: $data);
	}//end update()

	/**
	 * Add approved contacts of the owner to the plan. Owner only.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<int, string> $contactIds The rows of approved contacts.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (not the owner, a done plan, or a person who is no approved contact) or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function addParticipants(array $subject, string $id, array $contactIds): string {
		$plan = $this->planFor(subject: $subject, id: $id);
		if ($plan === null) {
			return self::NOT_FOUND;
		}

		if ($this->isOwner(plan: $plan, subject: $subject) === false || ($plan['status'] ?? 'running') === 'done') {
			return self::FORBIDDEN;
		}

		$added = $this->participantRefs(owner: $this->refOf(subject: $subject), organisation: $this->orgOf(subject: $subject), contactIds: $contactIds);
		if ($added === null) {
			return self::FORBIDDEN;
		}

		$all = array_values(array_unique(array_merge($this->participantsOf(plan: $plan), $added)));

		return $this->write(subject: $subject, plan: $plan, isOwner: true, data: ['participants' => $all]);
	}//end addParticipants()

	/**
	 * Take a participant off the plan. Owner only; the owner cannot be removed.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $ref The participant's subject reference.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function removeParticipant(array $subject, string $id, string $ref): string {
		$plan = $this->planFor(subject: $subject, id: $id);
		if ($plan === null) {
			return self::NOT_FOUND;
		}

		$owns = $this->isOwner(plan: $plan, subject: $subject);
		if ($owns === false || ($plan['status'] ?? 'running') === 'done' || $ref === (string)($plan['owner'] ?? '')) {
			return self::FORBIDDEN;
		}

		$left = array_values(array_filter($this->participantsOf(plan: $plan), static fn (string $one): bool => $one !== $ref));

		return $this->write(subject: $subject, plan: $plan, isOwner: true, data: ['participants' => $left]);
	}//end removeParticipant()

	/**
	 * Delete a plan and its actions. Owner only.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function delete(array $subject, string $id): string {
		$plan = $this->planFor(subject: $subject, id: $id);
		if ($plan === null) {
			return self::NOT_FOUND;
		}

		if ($this->isOwner(plan: $plan, subject: $subject) === false) {
			return self::FORBIDDEN;
		}

		$organisation = (string)($subject['organisation'] ?? '');
		foreach ($this->actionsOf(planId: $id, organisation: $organisation) as $action) {
			$this->writer->deleteObject(
				register: self::REGISTER,
				schema: 'portalAction',
				scopeField: 'plan',
				subjectRef: $id,
				organisation: $organisation,
				id: $this->idOf(row: $action)
			);
		}

		$deleted = $this->writer->deleteObject(
			register: self::REGISTER,
			schema: 'portalPlan',
			scopeField: 'owner',
			subjectRef: $this->refOf(subject: $subject),
			organisation: $organisation,
			id: $id
		);
		if ($deleted === false) {
			return self::FAILED;
		}

		return self::OK;
	}//end delete()

	/**
	 * Add an action to a plan. Any participant may.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<string, mixed> $data `title`, and optionally `kind`, `endDate`, `description` and `assignee`.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (a done plan), INVALID or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	public function addAction(array $subject, string $id, array $data): string {
		$plan = $this->planFor(subject: $subject, id: $id);
		if ($plan === null) {
			return self::NOT_FOUND;
		}

		if (($plan['status'] ?? 'running') === 'done') {
			return self::FORBIDDEN;
		}

		$members = array_merge([(string)($plan['owner'] ?? '')], $this->participantsOf(plan: $plan));
		$clean   = $this->actionFields->clean(data: $data, members: $members, requireTitle: true);
		if ($clean === null) {
			return self::INVALID;
		}

		if (isset($clean['assignee']) === false) {
			$clean['assignee'] = (string)$subject['subjectRef'];
		}

		if ($this->createAction(subject: $subject, planId: $id, data: $clean) === false) {
			return self::FAILED;
		}

		return self::OK;
	}//end addAction()

	/**
	 * Change an action of a plan. Any participant may.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $actionId The action.
	 * @param array<string, mixed> $data The fields to change.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (a done plan), INVALID or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	public function updateAction(array $subject, string $id, string $actionId, array $data): string {
		$plan = $this->planFor(subject: $subject, id: $id);
		$organisation = (string)($subject['organisation'] ?? '');
		$ids   = array_map(fn (array $action): string => $this->idOf(row: $action), $this->actionsOf(planId: $id, organisation: $organisation));
		$known = in_array($actionId, $ids, true);

		if ($plan === null || $known === false) {
			return self::NOT_FOUND;
		}

		if (($plan['status'] ?? 'running') === 'done') {
			return self::FORBIDDEN;
		}

		$members = array_merge([(string)($plan['owner'] ?? '')], $this->participantsOf(plan: $plan));
		$clean   = $this->actionFields->clean(data: $data, members: $members, requireTitle: false);
		if ($clean === null || $clean === []) {
			return self::INVALID;
		}

		if (array_key_exists('endDate', $clean) === true) {
			$clean['reminderSentAt'] = null;
		}

		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: 'portalAction',
			scopeField: 'plan',
			subjectRef: $id,
			organisation: $organisation,
			id: $actionId,
			data: $clean
		);
		if ($written === null) {
			return self::FAILED;
		}

		return self::OK;
	}//end updateAction()

	/**
	 * The plan as rows of a PDF for a participant: goal, end date, who takes
	 * part, each action and the note.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array{title: string, columns: array<int, array<string, string>>, rows: array<int, array<string, string>>}|null Null for a stranger.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t06
	 */
	public function pdfRows(array $subject, string $id, string $today): ?array {
		$plan = $this->detail(subject: $subject, id: $id, today: $today);
		if ($plan === null) {
			return null;
		}

		$rows = [
			['part' => $this->l10n->t('Goal'), 'content' => trim($plan['goal'].' '.$plan['goalDetail'])],
			['part' => $this->l10n->t('End date'), 'content' => $plan['endDate']],
			['part' => $this->l10n->t('Participants'), 'content' => implode(', ', array_column($plan['participants'], 'displayName'))],
		];
		foreach ($plan['actions'] as $action) {
			$until  = '';
			if ($action['endDate'] !== '') {
				$until = ', '.$action['endDate'];
			}

			$rows[] = ['part' => $this->l10n->t('Action'), 'content' => $action['title'].' ('.$action['status'].$until.')'];
		}

		if ($plan['note']['text'] !== '') {
			$rows[] = ['part' => $this->l10n->t('Note'), 'content' => $plan['note']['text']];
		}

		return [
			'title'   => $plan['title'],
			'columns' => [['key' => 'part', 'label' => $this->l10n->t('Part')], ['key' => 'content', 'label' => $this->l10n->t('Content')]],
			'rows'    => $rows,
		];
	}//end pdfRows()

	/**
	 * The plans a resident owns or takes part in, once each.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return array<int, array<string, mixed>> The plan rows.
	 */
	private function plansOf(array $subject): array {
		$ref          = (string)($subject['subjectRef'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		if ($ref === '') {
			return [];
		}

		$plans = [];
		foreach (['owner', 'participants'] as $scopeField) {
			$rows = $this->reader->readCollection(
				register: self::REGISTER,
				schema: 'portalPlan',
				scopeField: $scopeField,
				subjectRef: $ref,
				organisation: $organisation,
				limit: 200
			);
			foreach ($rows as $row) {
				$plans[$this->idOf(row: $row)] = $row;
			}
		}

		unset($plans['']);

		return array_values($plans);
	}//end plansOf()

	/**
	 * One plan the viewer owns or takes part in, or null.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 *
	 * @return array<string, mixed>|null
	 */
	private function planFor(array $subject, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->plansOf(subject: $subject) as $plan) {
			if ($this->idOf(row: $plan) === $id) {
				return $plan;
			}
		}

		return null;
	}//end planFor()

	/**
	 * Whether the viewer made the plan.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return bool
	 */
	private function isOwner(array $plan, array $subject): bool {
		return (string)($plan['owner'] ?? '') === (string)($subject['subjectRef'] ?? '') && (string)($plan['owner'] ?? '') !== '';
	}//end isOwner()

	/**
	 * The participants' references of a plan.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 *
	 * @return array<int, string>
	 */
	private function participantsOf(array $plan): array {
		$list = $plan['participants'] ?? [];
		if (is_array($list) === false) {
			return [];
		}

		return array_values(array_filter($list, static fn ($ref): bool => is_string($ref) === true && $ref !== ''));
	}//end participantsOf()

	/**
	 * The references behind some approved contacts, or null when any of them is not one.
	 *
	 * @param string $owner The plan's owner.
	 * @param string $organisation The tenant.
	 * @param array<int, mixed> $contactIds The contact rows asked for.
	 *
	 * @return array<int, string>|null The references.
	 */
	private function participantRefs(string $owner, string $organisation, array $contactIds): ?array {
		$approved = [];
		foreach ($this->contacts->approvedContacts(owner: $owner, organisation: $organisation) as $contact) {
			$approved[$contact['id']] = $contact['ref'];
		}

		$refs = [];
		foreach ($contactIds as $contactId) {
			if (is_string($contactId) === false || isset($approved[$contactId]) === false) {
				return null;
			}

			$refs[] = $approved[$contactId];
		}

		return array_values(array_unique($refs));
	}//end participantRefs()

	/**
	 * Save fields on a plan as the viewer: the owner by owner, a participant by membership.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $plan The plan row.
	 * @param bool $isOwner Whether the viewer made the plan.
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return string OK or FAILED.
	 */
	private function write(array $subject, array $plan, bool $isOwner, array $data): string {
		$scope = 'participants';
		if ($isOwner === true) {
			$scope = 'owner';
		}

		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: 'portalPlan',
			scopeField: $scope,
			subjectRef: (string)$subject['subjectRef'],
			organisation: (string)($subject['organisation'] ?? ''),
			id: $this->idOf(row: $plan),
			data: $data
		);
		if ($written === null) {
			return self::FAILED;
		}

		return self::OK;
	}//end write()

	/**
	 * The plan's actions.
	 *
	 * @param string $planId The plan.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function actionsOf(string $planId, string $organisation): array {
		return $this->reader->readCollection(
			register: self::REGISTER,
			schema: 'portalAction',
			scopeField: 'plan',
			subjectRef: $planId,
			organisation: $organisation,
			limit: 200
		);
	}//end actionsOf()

	/**
	 * Create one action of a plan.
	 *
	 * @param array<string, mixed> $subject The session subject, who writes it.
	 * @param string $planId The plan.
	 * @param array<string, mixed> $data The action's fields, with its assignee.
	 *
	 * @return bool
	 */
	private function createAction(array $subject, string $planId, array $data): bool {
		$row = $this->writer->createObject(
			register: self::REGISTER,
			schema: 'portalAction',
			scopeField: 'owner',
			subjectRef: (string)$subject['subjectRef'],
			organisation: (string)($subject['organisation'] ?? ''),
			data: $data + ['plan' => $planId, 'status' => 'todo', 'kind' => 'once']
		);

		return $row !== null;
	}//end createAction()

	/**
	 * The session subject's reference.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return string
	 */
	private function refOf(array $subject): string {
		return (string)($subject['subjectRef'] ?? '');
	}//end refOf()

	/**
	 * The session subject's tenant.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return string
	 */
	private function orgOf(array $subject): string {
		return (string)($subject['organisation'] ?? '');
	}//end orgOf()

	/**
	 * A row's identifier, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return $this->rules->idOf(row: $row);
	}//end idOf()
}//end class
