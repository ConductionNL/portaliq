<?php

/**
 * Portaliq Plan End Reminder Service Test
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\BackgroundJob\ActionReminderJob;
use OCA\Portaliq\Service\Plans\PlanEndReminderService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-SPL-004: one reminder per end date, to every participant.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */
class PlanEndReminderServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * A service over a store holding the given plans and actions, and the store.
	 *
	 * @param array<int, array<string, mixed>> $plans The stored plans.
	 * @param array<int, array<string, mixed>> $actions The stored actions.
	 *
	 * @return array{0: PlanEndReminderService, 1: object}
	 */
	private function service(array $plans, array $actions): array {
		$store = new class ($plans, $actions) {
			/**
			 * @var array<int, array{schema: string, uuid: ?string, object: array<string, mixed>}>
			 */
			public array $saved = [];

			private string $schema = '';

			public function __construct(public array $plans, public array $actions) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$rows = ($this->schema === 'portalPlan') ? $this->plans : $this->actions;
				foreach ($config['filters'] as $field => $value) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row[$field] ?? null) === $value));
				}

				return $rows;
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved[] = ['schema' => (string)$schema, 'uuid' => $uuid, 'object' => $object];
				if ($schema === 'portalPlan') {
					foreach ($this->plans as $i => $plan) {
						if ($plan['id'] === $uuid) {
							$this->plans[$i] = $object + $plan;
						}
					}
				}

				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => ($id === self::OS) ? $store : throw new RuntimeException('no service'));

		return [new PlanEndReminderService($container, $this->createMock(LoggerInterface::class)), $store];
	}//end service()

	/**
	 * Sanne's plan from the board: ends 20 October, Mark takes part.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(array $extra=[]): array {
		return $extra + ['id' => 'p1', 'owner' => 'sanne', 'participants' => ['mark'], 'organisation' => 'org-1', 'title' => 'Schuldhulp op orde', 'status' => 'running', 'endDate' => '2026-10-20'];
	}//end plan()

	/**
	 * Three open actions and two done.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function actions(): array {
		$out = [];
		foreach (['done', 'done', 'todo', 'doing', 'todo'] as $i => $status) {
			$out[] = ['id' => 'a'.$i, 'plan' => 'p1', 'status' => $status];
		}

		return $out + [9 => ['id' => 'other', 'plan' => 'p2', 'status' => 'todo']];
	}//end actions()

	/**
	 * Twelve days left with open actions: one message each for Sanne and Mark, once.
	 *
	 * @return void
	 */
	public function testEveryParticipantGetsOneReminder(): void {
		[$service, $store] = $this->service([self::plan()], self::actions());
		$this->assertSame(0, $service->remindDue('2026-10-05'), 'fifteen days left');
		$this->assertSame(1, $service->remindDue('2026-10-08'));
		$messages = array_values(array_filter($store->saved, static fn (array $s): bool => $s['schema'] === 'portalMessage'));
		$this->assertSame(['sanne', 'mark'], array_column(array_column($messages, 'object'), 'subjectRef'));
		$this->assertSame('plan-ending', $messages[0]['object']['ruleKey']);
		$this->assertSame('Het plan Schuldhulp op orde loopt over 12 dagen af', $messages[0]['object']['subject']);
		$this->assertStringContainsString('2026-10-20', $messages[0]['object']['body']);
		$this->assertStringContainsString('3 acties', $messages[0]['object']['body']);
		$this->assertNotEmpty($store->plans[0]['endReminderSentAt']);

		$this->assertSame(0, $service->remindDue('2026-10-08'), 'the same day again sends nothing');
		$this->assertSame(0, $service->remindDue('2026-10-09'), 'one per end date');
		$this->assertCount(2, array_filter($store->saved, static fn (array $s): bool => $s['schema'] === 'portalMessage'));
	}//end testEveryParticipantGetsOneReminder()

	/**
	 * A changed end date earns a new reminder once the mark is cleared.
	 *
	 * @return void
	 */
	public function testANewEndDateEarnsANewReminder(): void {
		[$service, $store] = $this->service([self::plan(['endReminderSentAt' => '2026-10-08T07:00:00+00:00'])], self::actions());
		$this->assertSame(0, $service->remindDue('2026-10-09'));
		$store->plans[0]['endDate']          = '2026-11-10';
		$store->plans[0]['endReminderSentAt'] = null;
		$this->assertSame(0, $service->remindDue('2026-10-09'), 'not yet near the new date');
		$this->assertSame(1, $service->remindDue('2026-10-28'));
	}//end testANewEndDateEarnsANewReminder()

	/**
	 * A plan with nothing open, a done plan and a plan without an end date are left alone.
	 *
	 * @return void
	 */
	public function testNothingToRemindAbout(): void {
		[$service, $store] = $this->service([self::plan()], [['id' => 'a', 'plan' => 'p1', 'status' => 'done']]);
		$this->assertSame(0, $service->remindDue('2026-10-08'), 'every action is done');
		[$service] = $this->service([self::plan(['status' => 'done'])], self::actions());
		$this->assertSame(0, $service->remindDue('2026-10-08'));
		[$service, $store] = $this->service([self::plan(['endDate' => ''])], self::actions());
		$this->assertSame(0, $service->remindDue('2026-10-08'));
		$this->assertSame([], $store->saved);
	}//end testNothingToRemindAbout()

	/**
	 * The daily job runs the plan reminders too.
	 *
	 * @return void
	 */
	public function testTheDailyJobRunsThePlanReminders(): void {
		$source = (string)file_get_contents(__DIR__.'/../../../../lib/BackgroundJob/ActionReminderJob.php');
		$this->assertStringContainsString('$this->planReminders->remindDue()', $source);
		$this->assertTrue(is_subclass_of(ActionReminderJob::class, \OCP\BackgroundJob\TimedJob::class));
	}//end testTheDailyJobRunsThePlanReminders()
}//end class
