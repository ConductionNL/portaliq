<?php

/**
 * Portaliq Portal Action Service
 *
 * A resident's own actions (Mijn acties) and the actions of the plans they
 * take part in: the list, one action, its file and its history, and what
 * each person may change.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\Plans\PlanActionFields;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Http\StreamResponse;

/**
 * A resident reads an action they own or are assigned to, or one in a plan
 * they take part in; an unknown id and someone else's id answer the same.
 * The owner changes every field and deletes. An assignee who is not the
 * owner changes the status, the description and the file. In a plan every
 * participant changes every field, the assignee among the participants, and
 * the action's owner or the plan's owner deletes it; a done plan is read-only.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- reader, writer, files, contacts, plans and history meet here.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the constructor takes the services an action touches.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- one method per thing a resident does with an action.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md
 */
class PortalActionService {

	public const SCHEMA = 'portalAction';

	/**
	 * The fields an assignee who is not the owner may change.
	 *
	 * @var array<int, string>
	 */
	private const ASSIGNEE_FIELDS = ['status', 'description', 'file'];

	/**
	 * Cleans the fields an action may be sent with.
	 *
	 * @var PlanActionFields
	 */
	private readonly PlanActionFields $fields;

	/**
	 * Decides what the viewer may do with an action.
	 *
	 * @var ActionAccess
	 */
	private readonly ActionAccess $access;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads actions.
	 * @param PortalObjectWriter $writer Writes actions.
	 * @param PortalContactService $contacts The owner's approved contacts, who may be assigned.
	 * @param PortalAccountLookup $accounts Reads a person's name.
	 * @param PortalPlanService $plans Whether the viewer takes part in an action's plan.
	 * @param PortalFileReader $fileReader Streams an action's file.
	 * @param PortalFileWriter $fileWriter Attaches a file to an action.
	 * @param CitizenDocumentUpload $upload Reads the multipart upload.
	 * @param ActionHistory $history Reads the audit trail of an action.
	 * @param ActionUpload $types Accepts PDF, JPG or PNG up to 10 MB.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalContactService $contacts,
		private readonly PortalAccountLookup $accounts,
		private readonly PortalPlanService $plans,
		private readonly PortalFileReader $fileReader,
		private readonly PortalFileWriter $fileWriter,
		private readonly CitizenDocumentUpload $upload,
		private readonly ActionHistory $history,
		private readonly ActionUpload $types = new ActionUpload(),
	) {
		$this->fields = new PlanActionFields();
		$this->access = new ActionAccess(contacts: $contacts);
	}//end __construct()

	/**
	 * The resident's actions, with and without a plan, the count done, and
	 * the people a new action can be given to.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return array{actions: array<int, array<string, mixed>>, done: int, total: int, assignees: array<int, array{ref: string, displayName: string}>}
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function overview(array $subject): array {
		$plans   = [];
		$actions = [];
		foreach ($this->ownRows(subject: $subject) as $row) {
			$planId = (string)($row['plan'] ?? '');
			if ($planId !== '' && array_key_exists($planId, $plans) === false) {
				$plans[$planId] = $this->plans->membership(subject: $subject, id: $planId);
			}

			$access    = $this->access->forAction(subject: $subject, row: $row, membership: ($plans[$planId] ?? null));
			$actions[] = $this->view(row: $row, access: $access);
		}

		// Open actions first, the soonest end date first; done actions last.
		$key = static fn (array $action): array => [$action['status'] === 'done', $action['endDate'] === '', $action['endDate']];
		usort($actions, static fn (array $a, array $b): int => $key($a) <=> $key($b));

		return [
			'actions'   => $actions,
			'done'      => count(array_filter($actions, static fn (array $action): bool => $action['status'] === 'done')),
			'total'     => count($actions),
			'assignees' => $this->people(refs: $this->access->ownMembers(subject: $subject)),
		];
	}//end overview()

	/**
	 * Add an action to the resident's own list.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $data `title`, and optionally `description`, `kind`, `status`, `endDate` and `assignee`.
	 *
	 * @return array{status: string, id: string} OK with the new id, INVALID or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function create(array $subject, array $data): array {
		$ref   = (string)($subject['subjectRef'] ?? '');
		$clean = $this->fields->clean(data: $data, members: $this->access->ownMembers(subject: $subject), requireTitle: true);
		if ($ref === '' || $clean === null) {
			return ['status' => PortalPlanService::INVALID, 'id' => ''];
		}

		$row = $this->writer->createObject(
			register: PortalPlanService::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: $ref,
			organisation: (string)($subject['organisation'] ?? ''),
			data: $this->stamped(subject: $subject, data: $clean + ['assignee' => $ref, 'status' => 'todo', 'kind' => 'once'])
		);
		if ($row === null) {
			return ['status' => PortalPlanService::FAILED, 'id' => ''];
		}

		return ['status' => PortalPlanService::OK, 'id' => $this->idOf(row: $row)];
	}//end create()

	/**
	 * Change an action, within what the viewer may change.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param array<string, mixed> $data The fields to change; `file` only as '' to take the file off.
	 * @param string $planId The plan the action is in, for a participant who is not its owner or assignee.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN, INVALID or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function update(array $subject, string $id, array $data, string $planId=''): string {
		$found = $this->find(subject: $subject, id: $id, planId: $planId);
		if ($found === null) {
			return PortalPlanService::NOT_FOUND;
		}

		[$row, $access] = $found;
		if ($access['readOnly'] === true) {
			return PortalPlanService::FORBIDDEN;
		}

		if ($access['role'] === 'assignee' && array_diff(array_keys($data), self::ASSIGNEE_FIELDS) !== []) {
			return PortalPlanService::FORBIDDEN;
		}

		$clean = $this->cleaned(row: $row, members: $access['members'], data: $data);
		if ($clean === null) {
			return PortalPlanService::INVALID;
		}

		return $this->write(subject: $subject, id: $id, access: $access, data: $clean);
	}//end update()

	/**
	 * Delete an action: its owner, or in a plan also the plan's owner.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function delete(array $subject, string $id, string $planId=''): string {
		$found = $this->find(subject: $subject, id: $id, planId: $planId);
		if ($found === null) {
			return PortalPlanService::NOT_FOUND;
		}

		$access = $found[1];
		if ($access['mayDelete'] === false) {
			return PortalPlanService::FORBIDDEN;
		}

		$deleted = $this->writer->deleteObject(
			register: PortalPlanService::REGISTER,
			schema: self::SCHEMA,
			scopeField: $access['scopeField'],
			subjectRef: $access['scopeValue'],
			organisation: (string)($subject['organisation'] ?? ''),
			id: $id
		);

		if ($deleted === false) {
			return PortalPlanService::FAILED;
		}

		return PortalPlanService::OK;
	}//end delete()

	/**
	 * The history of an action, newest first, or null for a stranger.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return array<int, array<string, string>>|null The lines.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function history(array $subject, string $id, string $planId=''): ?array {
		$found = $this->find(subject: $subject, id: $id, planId: $planId);
		if ($found === null) {
			return null;
		}

		return $this->history->lines(uuid: $this->idOf(row: $found[0]), nameOf: fn (string $ref): string => $this->nameOf(ref: $ref));
	}//end history()

	/**
	 * Keep one file with an action, replacing the one it had.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param array<string, mixed>|null $uploaded The multipart entry.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN, INVALID (not a PDF, JPG or PNG up to 10 MB) or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function attach(array $subject, string $id, ?array $uploaded, string $planId=''): string {
		$found = $this->find(subject: $subject, id: $id, planId: $planId);
		if ($found === null) {
			return PortalPlanService::NOT_FOUND;
		}

		$access = $found[1];
		if ($access['readOnly'] === true) {
			return PortalPlanService::FORBIDDEN;
		}

		$file = $this->upload->read($uploaded);
		if ($file === null || $this->types->accepts(name: $file['name'], content: $file['content']) === false) {
			return PortalPlanService::INVALID;
		}

		$attached = $this->fileWriter->attachFile(
			register: PortalPlanService::REGISTER,
			schema: self::SCHEMA,
			id: $id,
			fileName: $this->upload->uniqueName(
				existing: $this->fileReader->listFiles(register: PortalPlanService::REGISTER, schema: self::SCHEMA, id: $id),
				fileName: $file['name']
			),
			content: $file['content']
		);
		$fileId = (string)($attached['id'] ?? '');
		if ($fileId === '') {
			return PortalPlanService::FAILED;
		}

		return $this->write(subject: $subject, id: $id, access: $access, data: ['file' => $fileId, 'fileName' => $file['name']]);
	}//end attach()

	/**
	 * The action's file, or null for a stranger or an action without one.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return StreamResponse|null
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function file(array $subject, string $id, string $planId=''): ?StreamResponse {
		$found  = $this->find(subject: $subject, id: $id, planId: $planId);
		$fileId = (string)($found[0]['file'] ?? '');
		if ($found === null || $fileId === '') {
			return null;
		}

		return $this->fileReader->streamFile(register: PortalPlanService::REGISTER, schema: self::SCHEMA, id: $id, fileId: $fileId);
	}//end file()

	/**
	 * The action with what the viewer may do, or null.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan, for an action read through a plan.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null The row and the access.
	 */
	private function find(array $subject, string $id, string $planId): ?array {
		if ($id === '') {
			return null;
		}

		$rows = $this->ownRows(subject: $subject);
		if ($planId !== '') {
			$rows = $this->reader->readCollection(
				register: PortalPlanService::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'plan',
				subjectRef: $planId,
				organisation: (string)($subject['organisation'] ?? '')
			);
		}

		foreach ($rows as $row) {
			if ($this->idOf(row: $row) !== $id) {
				continue;
			}

			$rowPlan    = (string)($row['plan'] ?? '');
			$membership = null;
			if ($rowPlan !== '') {
				$membership = $this->plans->membership(subject: $subject, id: $rowPlan);
			}

			$access = $this->access->forAction(subject: $subject, row: $row, membership: $membership);
			if ($access === null) {
				return null;
			}

			return [$row, $access];
		}

		return null;
	}//end find()

	/**
	 * The fields to write for a change, or null when any is not allowed or nothing changes.
	 *
	 * @param array<string, mixed> $row The action.
	 * @param array<int, string> $members Who the action may be given to.
	 * @param array<string, mixed> $data The sent fields.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cleaned(array $row, array $members, array $data): ?array {
		$clean   = $this->fields->clean(data: $data, members: $members, requireTitle: false);
		$removal = $this->fileRemoval(data: $data);
		if ($clean === null || $removal === null || ($clean + $removal) === []) {
			return null;
		}

		$clean += $removal;
		if (array_key_exists('endDate', $clean) === true && $clean['endDate'] !== (string)($row['endDate'] ?? '')) {
			$clean['reminderSentAt'] = null;
		}

		return $clean;
	}//end cleaned()

	/**
	 * Save fields on an action through the viewer's own scope, stamped with who and when.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param array<string, mixed> $access The viewer's access.
	 * @param array<string, mixed> $data The cleaned fields.
	 *
	 * @return string OK or FAILED.
	 */
	private function write(array $subject, string $id, array $access, array $data): string {
		$written = $this->writer->updateObject(
			register: PortalPlanService::REGISTER,
			schema: self::SCHEMA,
			scopeField: $access['scopeField'],
			subjectRef: $access['scopeValue'],
			organisation: (string)($subject['organisation'] ?? ''),
			id: $id,
			data: $this->stamped(subject: $subject, data: $data)
		);

		if ($written === null) {
			return PortalPlanService::FAILED;
		}

		return PortalPlanService::OK;
	}//end write()

	/**
	 * The fields with who wrote them and when.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return array<string, mixed>
	 */
	private function stamped(array $subject, array $data): array {
		return $data + [
			'editedBy' => (string)($subject['subjectRef'] ?? ''),
			'editedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
	}//end stamped()

	/**
	 * Taking the file off: `file` sent as '' clears the file and its name.
	 *
	 * @param array<string, mixed> $data The sent fields.
	 *
	 * @return array<string, string>|null Nothing to do, the cleared fields, or null for any other `file` value.
	 */
	private function fileRemoval(array $data): ?array {
		if (array_key_exists('file', $data) === false) {
			return [];
		}

		if ($data['file'] !== '') {
			return null;
		}

		return ['file' => '', 'fileName' => ''];
	}//end fileRemoval()

	/**
	 * The actions the resident owns or is assigned to.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ownRows(array $subject): array {
		return $this->reader->readCollectionByAnyScope(
			register: PortalPlanService::REGISTER,
			schema: self::SCHEMA,
			scopeFields: ['owner', 'assignee'],
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? '')
		);
	}//end ownRows()

	/**
	 * An action as the screen shows it, with what the viewer may do.
	 *
	 * @param array<string, mixed> $row The action.
	 * @param array<string, mixed>|null $access The viewer's access.
	 *
	 * @return array<string, mixed>
	 */
	private function view(array $row, ?array $access): array {
		$assignee = (string)($row['assignee'] ?? '');

		return [
			'id'           => $this->idOf(row: $row),
			'title'        => (string)($row['title'] ?? ''),
			'description'  => (string)($row['description'] ?? ''),
			'kind'         => (string)($row['kind'] ?? 'once'),
			'status'       => (string)($row['status'] ?? 'todo'),
			'endDate'      => substr((string)($row['endDate'] ?? ''), 0, 10),
			'assignee'     => $assignee,
			'assigneeName' => $this->nameOf(ref: $assignee),
			'fileName'     => (string)($row['fileName'] ?? ''),
			'plan'         => (string)($row['plan'] ?? ''),
			'planTitle'    => (string)($access['planTitle'] ?? ''),
			'role'         => (string)($access['role'] ?? ''),
			'mayDelete'    => (bool)($access['mayDelete'] ?? false),
			'readOnly'     => (bool)($access['readOnly'] ?? true),
			'assignees'    => $this->people(refs: (array)($access['members'] ?? [])),
		];
	}//end view()

	/**
	 * People by reference, with their names.
	 *
	 * @param array<int, string> $refs The subject references.
	 *
	 * @return array<int, array{ref: string, displayName: string}>
	 */
	private function people(array $refs): array {
		return array_map(fn (string $ref): array => ['ref' => $ref, 'displayName' => $this->nameOf(ref: $ref)], array_values($refs));
	}//end people()

	/**
	 * A person's name, or '' when the account is unknown.
	 *
	 * @param string $ref The subject reference.
	 *
	 * @return string
	 */
	private function nameOf(string $ref): string {
		if ($ref === '') {
			return '';
		}

		return (string)($this->accounts->bySubjectRef(subjectRef: $ref)['displayName'] ?? '');
	}//end nameOf()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
