<?php

/**
 * Portaliq Plan End Reminder Service failure-path test
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

use OCA\Portaliq\Service\Plans\PlanEndReminderService;
use OCA\Portaliq\Service\Plans\PlanRules;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A store that cannot be read, or that answers nonsense, sends no reminder
 * and records none; a failed write is logged and not counted.
 *
 * @covers \OCA\Portaliq\Service\Plans\PlanEndReminderService
 * @uses   \OCA\Portaliq\Service\Plans\PlanRules
 */
class PlanEndReminderServiceFailureTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A service over the given store.
	 *
	 * @param object|null $store The ObjectService double; null makes the container fail.
	 *
	 * @return PlanEndReminderService
	 */
	private function service(?object $store): PlanEndReminderService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === self::OS && $store !== null) ? $store : throw new RuntimeException('OpenRegister is not installed')
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new PlanEndReminderService(container: $container, logger: $logger, rules: new PlanRules());
	}//end service()

	/**
	 * A store whose plan and action reads answer as given, and whose writes
	 * fail when told to.
	 *
	 * @param mixed $plans What a plan read answers, or a Throwable to throw.
	 * @param mixed $actions What an action read answers, or a Throwable to throw.
	 * @param bool $failWrites Whether saveObject throws.
	 *
	 * @return object
	 */
	private static function store(mixed $plans, mixed $actions, bool $failWrites = false): object {
		return new class ($plans, $actions, $failWrites) {
			/**
			 * @var array<int, string>
			 */
			public array $savedSchemas = [];

			private string $schema = '';

			public function __construct(private mixed $plans, private mixed $actions, private bool $failWrites) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$answer = ($this->schema === 'portalPlan') ? $this->plans : $this->actions;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failWrites === true) {
					throw new RuntimeException('store is read-only');
				}

				$this->savedSchemas[] = (string)$schema;
				return $object;
			}
		};
	}//end store()

	/**
	 * A plan twelve days from its end with Mark taking part.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(): array {
		return ['id' => 'p1', 'owner' => 'sanne', 'participants' => ['mark'], 'organisation' => 'org-1', 'title' => 'Schuldhulp op orde', 'status' => 'running', 'endDate' => '2026-10-20'];
	}//end plan()

	public function testWithoutOpenRegisterNothingIsSentAndTheReasonIsLogged(): void {
		$this->assertSame(0, $this->service(null)->remindDue('2026-10-08'));
		$this->assertSame([['Portaliq: plan reminders not read', ['reason' => 'OpenRegister is not installed']]], $this->warnings);
	}

	public function testAPlanReadThatFailsSendsNothing(): void {
		$store = self::store(new RuntimeException('index offline'), []);

		$this->assertSame(0, $this->service($store)->remindDue('2026-10-08'));
		$this->assertSame([['Portaliq: plan reminders not read', ['reason' => 'index offline']]], $this->warnings);
		$this->assertSame([], $store->savedSchemas);
	}

	public function testAPlanReadThatIsNotAListSendsNothing(): void {
		$store = self::store('not a list', []);

		$this->assertSame(0, $this->service($store)->remindDue('2026-10-08'));
		$this->assertSame([], $this->warnings);
		$this->assertSame([], $store->savedSchemas);
	}

	public function testAnActionReadThatFailsCountsAsNoOpenActionAndSendsNothing(): void {
		$store = self::store([self::plan()], new RuntimeException('actions offline'));

		$this->assertSame(0, $this->service($store)->remindDue('2026-10-08'));
		$this->assertSame([['Portaliq: plan actions not read', ['reason' => 'actions offline']]], $this->warnings);
		$this->assertSame([], $store->savedSchemas);
	}

	public function testAnActionReadThatIsNotAListSendsNothing(): void {
		$store = self::store([self::plan()], 'not a list');

		$this->assertSame(0, $this->service($store)->remindDue('2026-10-08'));
		$this->assertSame([], $store->savedSchemas);
	}

	public function testAFailedWriteIsLoggedAndNotCounted(): void {
		$store = self::store([self::plan()], [['id' => 'a1', 'plan' => 'p1', 'status' => 'todo']], failWrites: true);

		$this->assertSame(0, $this->service($store)->remindDue('2026-10-08'));
		$this->assertSame([['Portaliq: plan reminder failed', ['reason' => 'store is read-only']]], $this->warnings);
	}

	public function testTheSameStoreWithWorkingWritesSendsTheReminder(): void {
		$store = self::store([self::plan()], [['id' => 'a1', 'plan' => 'p1', 'status' => 'todo'], ['id' => 'a2', 'plan' => 'p1', 'status' => 'done']]);

		$this->assertSame(1, $this->service($store)->remindDue('2026-10-08'));
		$this->assertSame(['portalMessage', 'portalMessage', 'portalPlan'], $store->savedSchemas);
		$this->assertSame([], $this->warnings);
	}
}
