<?php

/**
 * The reminder three days before an action's end date, sent once
 * (personal-action-list).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\BackgroundJob\ActionReminderJob;
use OCA\Portaliq\Service\ActionReminderService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ActionReminderServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * A service over a store holding the given actions, and the store.
	 *
	 * @param array<int, array<string, mixed>> $actions The stored actions.
	 *
	 * @return array{0: ActionReminderService, 1: object}
	 */
	private function service(array $actions): array {
		$store = new class ($actions) {
			/**
			 * @var array<int, array{schema: string, uuid: ?string, object: array<string, mixed>}>
			 */
			public array $saved = [];

			private string $schema = '';

			public function __construct(public array $actions) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->actions;
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved[] = ['schema' => (string)$schema, 'uuid' => $uuid, 'object' => $object];
				if ($schema === 'portalAction') {
					foreach ($this->actions as $i => $action) {
						if ($action['id'] === $uuid) {
							$this->actions[$i] = $object + $action;
						}
					}
				}

				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => ($id === self::OS) ? $store : throw new RuntimeException('no service'));

		return [new ActionReminderService($container, $this->createMock(LoggerInterface::class)), $store];
	}

	private static function action(array $extra = []): array {
		return $extra + ['id' => 'a1', 'owner' => 'sanne', 'assignee' => 'sanne', 'organisation' => 'gemeente-x', 'title' => 'Bankafschriften uploaden', 'status' => 'todo', 'endDate' => '2026-10-14'];
	}

	public function testOnlyAnOpenActionThreeDaysAheadIsDue(): void {
		$this->assertTrue(ActionReminderService::isDue(self::action(), '2026-10-11'));
		$this->assertTrue(ActionReminderService::isDue(self::action(['status' => 'doing', 'endDate' => '2026-10-14T00:00:00+02:00']), '2026-10-11'));
		$this->assertFalse(ActionReminderService::isDue(self::action(), '2026-10-12'), 'two days ahead is not three');
		$this->assertFalse(ActionReminderService::isDue(self::action(), '2026-10-10'));
		$this->assertFalse(ActionReminderService::isDue(self::action(['status' => 'done']), '2026-10-11'));
		$this->assertFalse(ActionReminderService::isDue(self::action(['endDate' => '']), '2026-10-11'));
		$this->assertFalse(ActionReminderService::isDue(self::action(['assignee' => '']), '2026-10-11'));
		$this->assertFalse(ActionReminderService::isDue(self::action(), 'not a day'));
	}

	public function testExactlyOneReminderForTheSameEndDate(): void {
		[$service, $store] = $this->service([self::action()]);

		$this->assertSame(0, $service->remindDue('2026-10-10'));
		$this->assertSame(1, $service->remindDue('2026-10-11'));
		$this->assertSame(0, $service->remindDue('2026-10-11'), 'a second run the same day sends nothing');
		$this->assertSame(0, $service->remindDue('2026-10-12'));

		$messages = array_values(array_filter($store->saved, static fn (array $s): bool => $s['schema'] === 'portalMessage'));
		$this->assertCount(1, $messages);
		$this->assertSame('sanne', $messages[0]['object']['subjectRef']);
		$this->assertSame('Uw actie Bankafschriften uploaden loopt bijna af', $messages[0]['object']['subject']);
		$this->assertSame('action-due', $messages[0]['object']['ruleKey']);
		$this->assertSame('2026-10-14', $store->actions[0]['reminderForEndDate']);
	}

	public function testAChangedEndDateEarnsANewReminder(): void {
		[$service, $store] = $this->service([self::action(['reminderForEndDate' => '2026-10-14', 'reminderSentAt' => '2026-10-11T07:00:00+00:00'])]);

		$this->assertSame(0, $service->remindDue('2026-10-11'));

		$store->actions[0]['endDate'] = '2026-10-20';
		$this->assertSame(1, $service->remindDue('2026-10-17'));
	}

	public function testTheReminderGoesToTheAssigneeNotTheOwner(): void {
		[$service, $store] = $this->service([self::action(['assignee' => 'mark'])]);

		$service->remindDue('2026-10-11');

		$this->assertSame('mark', $store->saved[0]['object']['subjectRef']);
	}

	public function testTheJobRunsTheService(): void {
		$this->assertTrue(is_subclass_of(ActionReminderJob::class, \OCP\BackgroundJob\TimedJob::class));
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertStringContainsString('OCA\Portaliq\BackgroundJob\ActionReminderJob', $info, 'the job is registered');
	}
}
