<?php

/**
 * Portaliq Portal Plan Service refusal-path test
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every plan change answers the status of the step that stopped it: an
 * unknown plan is NOT_FOUND, a done plan FORBIDDEN, nothing to change
 * INVALID, and a store that refuses the write FAILED.
 *
 * @covers \OCA\Portaliq\Service\Plans\PortalPlanService
 * @uses   \OCA\Portaliq\Service\Plans\PlanRules
 * @uses   \OCA\Portaliq\Service\Plans\PlanTemplates
 * @uses   \OCA\Portaliq\Service\Plans\PlanViews
 * @uses   \OCA\Portaliq\Service\Plans\PlanFieldChanges
 * @uses   \OCA\Portaliq\Service\Plans\PlanActionFields
 */
class PortalPlanServiceRefusalTest extends TestCase {

	private PortalObjectReader&MockObject $reader;

	private PortalObjectWriter&MockObject $writer;

	/**
	 * A service whose store holds the given plans and actions and refuses every write.
	 *
	 * @param array<int, array<string, mixed>> $plans The plans the viewer owns or takes part in.
	 * @param array<int, array<string, mixed>> $actions The actions of every plan.
	 *
	 * @return PortalPlanService
	 */
	private function service(array $plans = [], array $actions = []): PortalPlanService {
		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->reader->method('readCollectionByAnyScope')->willReturn($plans);
		$this->reader->method('readCollection')->willReturn($actions);
		$this->writer = $this->createMock(PortalObjectWriter::class);
		$this->writer->method('createObject')->willReturn(null);
		$this->writer->method('updateObject')->willReturn(null);
		$this->writer->method('deleteObject')->willReturnCallback(static fn (string $register, string $schema): bool => $schema === 'portalAction');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PortalPlanService($this->reader, $this->writer, $this->createMock(PortalContactService::class), $this->createMock(PortalAccountLookup::class), $l10n);
	}//end service()

	/**
	 * Sanne as the session subject.
	 *
	 * @return array<string, string>
	 */
	private static function sanne(): array {
		return ['subjectRef' => 'sanne', 'organisation' => 'org-1'];
	}//end sanne()

	/**
	 * Sanne's running plan.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(array $extra = []): array {
		return $extra + ['id' => 'p1', 'owner' => 'sanne', 'participants' => ['mark'], 'title' => 'Schuldhulp op orde', 'status' => 'running', 'endDate' => '2026-10-20'];
	}//end plan()

	public function testAPlanTheStoreDoesNotCreateFails(): void {
		$service = $this->service();
		$this->writer->expects($this->once())->method('createObject')->with(
			'portaliq',
			'portalPlan',
			'owner',
			'sanne',
			'org-1',
			['owner' => 'sanne', 'participants' => [], 'title' => 'Mijn plan', 'status' => 'running', 'template' => '']
		);

		$this->assertSame(['status' => PortalPlanService::FAILED, 'id' => ''], $service->start(self::sanne(), 'zuid', '', 'Mijn plan', [], '2026-09-01'));
	}

	public function testAnUpdateWithNothingToChangeIsInvalidAndWritesNothing(): void {
		$service = $this->service([self::plan()]);
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertSame(PortalPlanService::INVALID, $service->update(self::sanne(), 'p1', [], '2026-09-01'));
	}

	public function testAnUpdateTheStoreRefusesFailsAndWritesThroughTheOwnersScope(): void {
		$service = $this->service([self::plan()]);
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portalPlan', 'owner', 'sanne', 'org-1', 'p1', ['goal' => 'Rust in de financiën']);

		$this->assertSame(PortalPlanService::FAILED, $service->update(self::sanne(), 'p1', ['goal' => ' Rust in de financiën '], '2026-09-01'));
	}

	public function testAParticipantsUpdateIsWrittenThroughTheirMembership(): void {
		$service = $this->service([self::plan()]);
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portalPlan', 'participants', 'mark', 'org-1', 'p1', ['goal' => 'Overzicht']);

		$this->assertSame(PortalPlanService::FAILED, $service->update(['subjectRef' => 'mark', 'organisation' => 'org-1'], 'p1', ['goal' => 'Overzicht'], '2026-09-01'));
	}

	public function testAPlanWithoutAnIdOrOutsideTheViewersPlansIsNotFound(): void {
		$service = $this->service([self::plan()]);
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertSame(PortalPlanService::NOT_FOUND, $service->removeParticipant(self::sanne(), '', 'mark'));
		$this->assertSame(PortalPlanService::NOT_FOUND, $service->removeParticipant(self::sanne(), 'p-other', 'mark'));
	}

	public function testWithoutASubjectReferenceNoPlanIsRead(): void {
		$service = $this->service([self::plan()]);
		$this->reader->expects($this->never())->method('readCollectionByAnyScope');

		$this->assertSame(PortalPlanService::NOT_FOUND, $service->delete(['subjectRef' => '', 'organisation' => 'org-1'], 'p1'));
	}

	public function testADeleteTheStoreRefusesFailsAfterTheActionsWentFirst(): void {
		$service = $this->service([self::plan()], [['id' => 'a1', 'plan' => 'p1'], ['id' => 'a2', 'plan' => 'p1']]);
		$deleted = [];
		$this->writer->method('deleteObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id) use (&$deleted): bool {
				$deleted[] = [$schema, $scopeField, $subjectRef, $id];
				return $schema === 'portalAction';
			}
		);

		$this->assertSame(PortalPlanService::FAILED, $service->delete(self::sanne(), 'p1'));
		$this->assertSame(
			[['portalAction', 'plan', 'p1', 'a1'], ['portalAction', 'plan', 'p1', 'a2'], ['portalPlan', 'owner', 'sanne', 'p1']],
			$deleted
		);
	}

	public function testAnActionTheStoreDoesNotCreateFails(): void {
		$service = $this->service([self::plan(['participants' => 'not a list'])]);
		$this->writer->expects($this->once())->method('createObject')->with(
			'portaliq',
			'portalAction',
			'owner',
			'sanne',
			'org-1',
			['title' => 'Bellen met de bank', 'assignee' => 'sanne', 'plan' => 'p1', 'status' => 'todo', 'kind' => 'once']
		);

		$this->assertSame(PortalPlanService::FAILED, $service->addAction(self::sanne(), 'p1', ['title' => 'Bellen met de bank']));
	}

	public function testAnActionOfADonePlanCannotChange(): void {
		$service = $this->service([self::plan(['status' => 'done'])], [['id' => 'a1', 'plan' => 'p1']]);
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertSame(PortalPlanService::FORBIDDEN, $service->updateAction(self::sanne(), 'p1', 'a1', ['status' => 'done']));
	}

	public function testAnActionChangeTheStoreRefusesFailsAndANewEndDateClearsTheReminder(): void {
		$service = $this->service([self::plan()], [['id' => 'a1', 'plan' => 'p1']]);
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portalAction', 'plan', 'p1', 'org-1', 'a1', ['endDate' => '2026-10-01', 'reminderSentAt' => null]);

		$this->assertSame(PortalPlanService::FAILED, $service->updateAction(self::sanne(), 'p1', 'a1', ['endDate' => '2026-10-01']));
	}
}
