<?php

/**
 * Portaliq Portal Plan Service Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Plans
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

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SPL-001 to REQ-SPL-003 and REQ-SPL-005 over an in-memory store that
 * scopes a read the way the portal's reader does: an equal value, or a list
 * that contains it.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/shared-plans/spec.md
 */
class PortalPlanServiceTest extends TestCase {

	/**
	 * The rows by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = ['portalPlan' => [], 'portalAction' => [], 'portalPlanTemplate' => []];

	private int $seq = 0;

	/**
	 * Whether a stored scope value matches: equal, or a list holding it.
	 *
	 * @param mixed $stored The stored value.
	 * @param string $value The scope value.
	 *
	 * @return bool
	 */
	private static function scoped(mixed $stored, string $value): bool {
		if (is_array($stored) === true) {
			return in_array($value, $stored, true);
		}

		return $stored === $value && $value !== '';
	}//end scoped()

	/**
	 * The service over the store.
	 *
	 * @return PortalPlanService
	 */
	private function service(): PortalPlanService {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='', int $limit=200): array => array_values(
				array_filter(
					$this->rows[$schema],
					static fn (array $row): bool => self::scoped($row[$scopeField] ?? null, $subjectRef) && (($row['organisation'] ?? '') === '' || $row['organisation'] === $organisation || $organisation === '')
				)
			)
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$id = 'o'.(++$this->seq);
				return ($this->rows[$schema][$id] = ($data + ['id' => $id, 'organisation' => $organisation]));
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, $data): ?array {
				$existing = ($this->rows[$schema][$id] ?? null);
				if ($existing === null || self::scoped($existing[$scopeField] ?? null, $subjectRef) === false) {
					return null;
				}

				$merged = ($data + $existing);
				if (is_array($existing[$scopeField] ?? null) === true) {
					// The real writer never edits a membership list through a member's scope.
					$merged[$scopeField] = $existing[$scopeField];
				}

				return ($this->rows[$schema][$id] = $merged);
			}
		);
		$writer->method('deleteObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id): bool {
				$existing = ($this->rows[$schema][$id] ?? null);
				if ($existing === null || self::scoped($existing[$scopeField] ?? null, $subjectRef) === false) {
					return false;
				}

				unset($this->rows[$schema][$id]);
				return true;
			}
		);
		$contacts = $this->createMock(PortalContactService::class);
		$contacts->method('approvedContacts')->willReturnCallback(
			static fn (string $owner): array => ($owner === 'sanne') ? [['id' => 'c-mark', 'ref' => 'mark', 'displayName' => 'Mark Jansen']] : []
		);
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->method('bySubjectRef')->willReturnCallback(static fn (string $ref): array => ['displayName' => ucfirst($ref)]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new PortalPlanService($reader, $writer, $contacts, $accounts, $l10n);
	}//end service()

	/**
	 * A session subject.
	 *
	 * @param string $ref The subject reference.
	 *
	 * @return array<string, mixed>
	 */
	private function as(string $ref): array {
		return ['subjectRef' => $ref, 'organisation' => 'org-1'];
	}//end as()

	/**
	 * Put the published template of the board in the store.
	 *
	 * @return void
	 */
	private function seedTemplate(): void {
		$this->rows['portalPlanTemplate']['t1'] = [
			'id' => 't1', 'portal' => 'zuid', 'title' => 'Schuldhulp op orde', 'goal' => 'Overzicht en regeling.', 'goalDetail' => 'Stap voor stap.',
			'durationDays' => 56, 'published' => true,
			'actions' => [['title' => 'A', 'offsetDays' => 7], ['title' => 'B', 'offsetDays' => 14], ['title' => 'C', 'offsetDays' => 21], ['title' => 'D', 'offsetDays' => 28], ['title' => 'E', 'offsetDays' => 35]],
		];
		$this->rows['portalPlanTemplate']['t2'] = ['id' => 't2', 'portal' => 'zuid', 'title' => 'Concept', 'published' => false];
		$this->rows['portalPlanTemplate']['t3'] = ['id' => 't3', 'portal' => 'noord', 'title' => 'Van een ander portaal', 'published' => true];
	}//end seedTemplate()

	/**
	 * Start Sanne's plan from the template with Mark, on 1 September.
	 *
	 * @param PortalPlanService $service The service.
	 *
	 * @return string The plan's id.
	 */
	private function startedPlan(PortalPlanService $service): string {
		$this->seedTemplate();
		$result = $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: 't1', title: '', contactIds: ['c-mark'], today: '2026-09-01');
		$this->assertSame('ok', $result['status']);

		return $result['id'];
	}//end startedPlan()

	/**
	 * Only the published templates of this portal are offered.
	 *
	 * @return void
	 */
	public function testOnlyThePublishedTemplatesOfThisPortalAreOffered(): void {
		$this->seedTemplate();
		$templates = $this->service()->templates(portal: 'zuid');
		$this->assertSame(['Schuldhulp op orde'], array_column($templates, 'title'));
		$this->assertSame(5, $templates[0]['actionCount']);
		$this->assertSame([], $this->service()->templates(portal: ''));
	}//end testOnlyThePublishedTemplatesOfThisPortalAreOffered()

	/**
	 * Debt help from a template: the goal, five actions and the end date, and Mark sees it.
	 *
	 * @return void
	 */
	public function testDebtHelpFromATemplate(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$plan    = $service->detail(subject: $this->as('sanne'), id: $id, today: '2026-09-01');
		$this->assertSame('Schuldhulp op orde', $plan['title'], 'the template names the plan');
		$this->assertSame('Overzicht en regeling.', $plan['goal']);
		$this->assertSame('2026-10-27', $plan['endDate']);
		$this->assertCount(5, $plan['actions']);
		$this->assertSame('2026-09-08', $plan['actions'][0]['endDate']);
		$this->assertSame(['sanne', 'mark'], array_column($plan['participants'], 'ref'));
		$this->assertTrue($plan['isOwner']);

		$seen = $service->detail(subject: $this->as('mark'), id: $id, today: '2026-09-01');
		$this->assertSame('participant', $seen['role']);
		$this->assertSame('Sanne', $seen['sharedBy']);
		$this->assertFalse($seen['isOwner']);
		$this->assertArrayNotHasKey('contacts', $seen, 'a participant is not offered the owner\'s contacts');
	}//end testDebtHelpFromATemplate()

	/**
	 * A plan can start empty, and a template of another portal or an unpublished one is refused.
	 *
	 * @return void
	 */
	public function testStartingEmptyAndRefusals(): void {
		$service = $this->service();
		$this->seedTemplate();
		$empty = $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: '', title: ' Mijn plan ', contactIds: [], today: '2026-09-01');
		$this->assertSame('ok', $empty['status']);
		$this->assertSame('Mijn plan', $service->detail(subject: $this->as('sanne'), id: $empty['id'], today: '2026-09-01')['title']);
		$this->assertSame('invalid', $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: '', title: '', contactIds: [], today: '2026-09-01')['status'], 'no name');
		$this->assertSame('invalid', $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: 't2', title: '', contactIds: [], today: '2026-09-01')['status'], 'unpublished');
		$this->assertSame('invalid', $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: 't3', title: '', contactIds: [], today: '2026-09-01')['status'], 'another portal');
		$this->assertSame('invalid', $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: '', title: str_repeat('x', 201), contactIds: [], today: '2026-09-01')['status']);
	}//end testStartingEmptyAndRefusals()

	/**
	 * Someone who is not an approved contact cannot be added, at the start or later.
	 *
	 * @return void
	 */
	public function testNotAContact(): void {
		$service = $this->service();
		$this->seedTemplate();
		$this->assertSame('forbidden', $service->start(subject: $this->as('sanne'), portal: 'zuid', templateId: '', title: 'P', contactIds: ['c-stranger'], today: '2026-09-01')['status']);
		$this->assertSame('forbidden', $service->start(subject: $this->as('lars'), portal: 'zuid', templateId: '', title: 'P', contactIds: ['c-mark'], today: '2026-09-01')['status'], 'another resident\'s contact');
		$this->assertSame([], $this->rows['portalPlan'], 'nothing was created');
		$id = $this->startedPlan($service);
		$this->assertSame('forbidden', $service->addParticipants(subject: $this->as('sanne'), id: $id, contactIds: ['c-stranger']));
		$this->assertSame(['mark'], $this->rows['portalPlan'][$id]['participants']);
	}//end testNotAContact()

	/**
	 * A resident who is not a participant gets nothing, whatever they ask.
	 *
	 * @return void
	 */
	public function testSomeoneElsesPlanIsNotFound(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$lars    = $this->as('lars');
		$this->assertNull($service->detail(subject: $lars, id: $id, today: '2026-09-01'));
		$this->assertSame(['plans' => [], 'counts' => ['running' => 0, 'action' => 0, 'done' => 0]], $service->overview(subject: $lars, today: '2026-09-01'));
		$this->assertSame('not_found', $service->update(subject: $lars, id: $id, changes: ['goal' => 'x'], today: '2026-09-01'));
		$this->assertSame('not_found', $service->delete(subject: $lars, id: $id));
		$this->assertSame('not_found', $service->addAction(subject: $lars, id: $id, data: ['title' => 'x']));
		$this->assertSame('not_found', $service->addParticipants(subject: $lars, id: $id, contactIds: []));
		$this->assertNull($service->pdfRows(subject: $lars, id: $id, today: '2026-09-01'));
		$actionId = array_key_first($this->rows['portalAction']);
		$this->assertSame('not_found', $service->updateAction(subject: $lars, id: $id, actionId: $actionId, data: ['status' => 'done']));
		$this->assertSame('not_found', $service->update(subject: $this->as('sanne'), id: 'nope', changes: ['goal' => 'x'], today: '2026-09-01'));
	}//end testSomeoneElsesPlanIsNotFound()

	/**
	 * Two plans, one made and one shared, with counts and who shared it.
	 *
	 * @return void
	 */
	public function testTwoRunningPlans(): void {
		$service = $this->service();
		$this->startedPlan($service);
		$this->rows['portalPlan']['p2'] = ['id' => 'p2', 'owner' => 'linda', 'participants' => ['sanne'], 'title' => 'Terug naar werk', 'status' => 'running', 'endDate' => '2027-03-31', 'organisation' => 'org-1'];
		$this->rows['portalPlan']['p3'] = ['id' => 'p3', 'owner' => 'sanne', 'participants' => [], 'title' => 'Oud', 'status' => 'done', 'doneAt' => '2025-01-01T10:00:00+01:00', 'organisation' => 'org-1'];
		$this->rows['portalPlan']['p4'] = ['id' => 'p4', 'owner' => 'sanne', 'participants' => [], 'title' => 'Klaar', 'status' => 'done', 'doneAt' => '2026-08-30T10:00:00+02:00', 'organisation' => 'org-1'];
		$this->rows['portalAction']['x1'] = ['id' => 'x1', 'plan' => 'p2', 'status' => 'todo', 'title' => 't', 'organisation' => 'org-1'];

		$overview = $service->overview(subject: $this->as('sanne'), today: '2026-10-08');
		$this->assertSame(['Schuldhulp op orde', 'Terug naar werk', 'Klaar'], array_column($overview['plans'], 'title'), 'a plan done more than a year ago is gone');
		$this->assertSame(['running' => 2, 'action' => 0, 'done' => 1], $overview['counts'], 'both run: Schuldhulp is 19 days from its end');
		$byTitle = array_column($overview['plans'], null, 'title');
		$this->assertSame('', $byTitle['Schuldhulp op orde']['sharedBy']);
		$this->assertSame('Linda', $byTitle['Terug naar werk']['sharedBy']);
		$this->assertSame(1, $byTitle['Terug naar werk']['openActions']);
		$this->assertSame('running', $byTitle['Schuldhulp op orde']['state']);

		$late = $service->overview(subject: $this->as('sanne'), today: '2026-10-20');
		$this->assertSame('action', array_column($late['plans'], null, 'title')['Schuldhulp op orde']['state'], 'seven days left with five open actions');
	}//end testTwoRunningPlans()

	/**
	 * A participant changes the goal, the note and the actions; the owner alone the rest.
	 *
	 * @return void
	 */
	public function testWhatAParticipantMayChange(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$mark    = $this->as('mark');
		$this->assertSame('ok', $service->update(subject: $mark, id: $id, changes: ['goal' => 'Nieuw doel', 'note' => 'Eerst de brieven.'], today: '2026-09-01'));
		$plan = $service->detail(subject: $this->as('sanne'), id: $id, today: '2026-09-01');
		$this->assertSame('Nieuw doel', $plan['goal']);
		$this->assertSame('Eerst de brieven.', $plan['note']['text']);
		$this->assertSame('Mark', $plan['note']['editedBy'], 'the note says who wrote it');
		$this->assertNotSame('', $plan['note']['editedAt']);

		foreach ([['endDate' => '2026-12-01'], ['title' => 'Mijn titel'], ['status' => 'done']] as $changes) {
			$this->assertSame('forbidden', $service->update(subject: $mark, id: $id, changes: $changes, today: '2026-09-01'), array_key_first($changes));
		}

		$this->assertSame('forbidden', $service->addParticipants(subject: $mark, id: $id, contactIds: ['c-mark']));
		$this->assertSame('forbidden', $service->removeParticipant(subject: $mark, id: $id, ref: 'sanne'), 'a participant cannot remove the owner');
		$this->assertSame('forbidden', $service->removeParticipant(subject: $this->as('sanne'), id: $id, ref: 'sanne'), 'nor can the owner');
		$this->assertSame('forbidden', $service->delete(subject: $mark, id: $id));
		$this->assertArrayHasKey($id, $this->rows['portalPlan']);
		$this->assertSame('invalid', $service->update(subject: $mark, id: $id, changes: ['goal' => ['x']], today: '2026-09-01'));
		$this->assertSame('invalid', $service->update(subject: $mark, id: $id, changes: ['owner' => 'mark'], today: '2026-09-01'), 'an unknown field changes nothing');
		$this->assertSame('sanne', $this->rows['portalPlan'][$id]['owner']);
	}//end testWhatAParticipantMayChange()

	/**
	 * The owner sets the end date, and a new end date clears the reminder mark.
	 *
	 * @return void
	 */
	public function testANewEndDateClearsTheReminder(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$this->rows['portalPlan'][$id]['endReminderSentAt'] = '2026-10-13T07:00:00+00:00';
		$sanne = $this->as('sanne');
		$this->assertSame('ok', $service->update(subject: $sanne, id: $id, changes: ['endDate' => '2026-10-27'], today: '2026-10-08'));
		$this->assertSame('2026-10-13T07:00:00+00:00', $this->rows['portalPlan'][$id]['endReminderSentAt'], 'the same date keeps the mark');
		$this->assertSame('ok', $service->update(subject: $sanne, id: $id, changes: ['endDate' => '2026-11-10'], today: '2026-10-08'));
		$this->assertNull($this->rows['portalPlan'][$id]['endReminderSentAt']);
		$this->assertSame('invalid', $service->update(subject: $sanne, id: $id, changes: ['endDate' => '10-11-2026'], today: '2026-10-08'));
	}//end testANewEndDateClearsTheReminder()

	/**
	 * The owner adds and removes a participant; a done plan is read-only.
	 *
	 * @return void
	 */
	public function testParticipantsAndADonePlan(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$sanne   = $this->as('sanne');
		$this->assertSame('ok', $service->removeParticipant(subject: $sanne, id: $id, ref: 'mark'));
		$this->assertSame([], $this->rows['portalPlan'][$id]['participants']);
		$this->assertNull($service->detail(subject: $this->as('mark'), id: $id, today: '2026-09-01'), 'Mark no longer sees it');
		$this->assertSame('ok', $service->addParticipants(subject: $sanne, id: $id, contactIds: ['c-mark', 'c-mark']));
		$this->assertSame(['mark'], $this->rows['portalPlan'][$id]['participants'], 'once');

		$this->assertSame('ok', $service->update(subject: $sanne, id: $id, changes: ['status' => 'done'], today: '2026-10-01'));
		$this->assertSame('done', $this->rows['portalPlan'][$id]['status']);
		$this->assertNotEmpty($this->rows['portalPlan'][$id]['doneAt']);
		$mark = $this->as('mark');
		$this->assertSame('forbidden', $service->update(subject: $mark, id: $id, changes: ['goal' => 'x'], today: '2026-10-01'));
		$this->assertSame('forbidden', $service->addAction(subject: $mark, id: $id, data: ['title' => 'x']));
		$this->assertSame('forbidden', $service->addParticipants(subject: $sanne, id: $id, contactIds: ['c-mark']));
		$this->assertFalse($service->detail(subject: $sanne, id: $id, today: '2026-10-01')['canEdit']);
	}//end testParticipantsAndADonePlan()

	/**
	 * Any participant adds and changes actions; the assignee must be in the plan.
	 *
	 * @return void
	 */
	public function testActionsInAPlan(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$mark    = $this->as('mark');
		$this->assertSame('ok', $service->addAction(subject: $mark, id: $id, data: ['title' => 'Budgetplan nakijken', 'endDate' => '2026-09-20', 'assignee' => 'sanne']));
		$plan = $service->detail(subject: $mark, id: $id, today: '2026-09-01');
		$this->assertCount(6, $plan['actions']);
		$added = array_values(array_filter($plan['actions'], static fn (array $a): bool => $a['title'] === 'Budgetplan nakijken'))[0];
		$this->assertSame('Sanne', $added['assigneeName']);
		$this->assertSame('todo', $added['status']);

		$this->assertSame('ok', $service->updateAction(subject: $mark, id: $id, actionId: $added['id'], data: ['status' => 'done']));
		$this->assertSame('done', $this->rows['portalAction'][$added['id']]['status']);
		$this->assertSame('invalid', $service->updateAction(subject: $mark, id: $id, actionId: $added['id'], data: ['status' => 'finished']));
		$this->assertSame('invalid', $service->updateAction(subject: $mark, id: $id, actionId: $added['id'], data: ['assignee' => 'lars']), 'not in the plan');
		$this->assertSame('invalid', $service->addAction(subject: $mark, id: $id, data: ['title' => '']));
		$this->assertSame('invalid', $service->updateAction(subject: $mark, id: $id, actionId: $added['id'], data: ['plan' => 'other']), 'an action cannot move to another plan');
		$this->assertSame($id, $this->rows['portalAction'][$added['id']]['plan']);
		$this->assertSame('not_found', $service->updateAction(subject: $mark, id: $id, actionId: 'nope', data: ['status' => 'done']));
		$this->rows['portalAction']['elsewhere'] = ['id' => 'elsewhere', 'plan' => 'p-other', 'status' => 'todo', 'title' => 'x'];
		$this->assertSame('not_found', $service->updateAction(subject: $mark, id: $id, actionId: 'elsewhere', data: ['status' => 'done']), 'an action of another plan');
		$this->assertSame('todo', $this->rows['portalAction']['elsewhere']['status']);

		$this->assertSame('ok', $service->updateAction(subject: $mark, id: $id, actionId: $added['id'], data: ['endDate' => '2026-09-25']));
	}//end testActionsInAPlan()

	/**
	 * Deleting a plan takes its actions along, and only the owner may.
	 *
	 * @return void
	 */
	public function testDeletingAPlan(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$this->rows['portalAction']['elsewhere'] = ['id' => 'elsewhere', 'plan' => 'p-other', 'status' => 'todo', 'title' => 'x'];
		$this->assertSame('ok', $service->delete(subject: $this->as('sanne'), id: $id));
		$this->assertSame([], $this->rows['portalPlan']);
		$this->assertSame(['elsewhere'], array_keys($this->rows['portalAction']), 'only its own actions go');
		$this->assertSame('not_found', $service->delete(subject: $this->as('sanne'), id: $id));
	}//end testDeletingAPlan()

	/**
	 * The PDF holds the goal, end date, participants, actions and the note.
	 *
	 * @return void
	 */
	public function testThePdfHoldsThePlan(): void {
		$service = $this->service();
		$id      = $this->startedPlan($service);
		$service->update(subject: $this->as('mark'), id: $id, changes: ['note' => 'Mark: eerst de brieven.'], today: '2026-09-01');
		$pdf = $service->pdfRows(subject: $this->as('mark'), id: $id, today: '2026-09-01');
		$this->assertSame('Schuldhulp op orde', $pdf['title']);
		$this->assertSame(['part', 'content'], array_column($pdf['columns'], 'key'));
		$parts = array_column($pdf['rows'], 'content', 'part');
		$this->assertSame('Overzicht en regeling. Stap voor stap.', $parts['Goal']);
		$this->assertSame('2026-10-27', $parts['End date']);
		$this->assertSame('Sanne, Mark', $parts['Participants']);
		$this->assertSame('Mark: eerst de brieven.', $parts['Note']);
		$this->assertCount(5, array_filter($pdf['rows'], static fn (array $row): bool => $row['part'] === 'Action'));
		$this->assertSame('A (todo, 2026-09-08)', array_values(array_filter($pdf['rows'], static fn (array $row): bool => $row['part'] === 'Action'))[0]['content']);
	}//end testThePdfHoldsThePlan()
}//end class
