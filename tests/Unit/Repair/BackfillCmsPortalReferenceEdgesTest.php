<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\BackfillCmsPortalReference;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portal-cms-content-model task 4, the edges: without OpenRegister nothing is
 * assigned; an object without a uuid, one whose schema cannot be applied for
 * the write and one whose save fails stay without a portal and are counted;
 * the uuid is read from @self.uuid, @self.id or id; entities are serialised;
 * a schema that cannot be read is skipped; content is read past one page.
 *
 * @covers \OCA\Portaliq\Repair\BackfillCmsPortalReference
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
 *
 * @spec openspec/changes/archive/2026-10-09-portal-cms-content-model/tasks.md#task-4
 */
class BackfillCmsPortalReferenceEdgesTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public array $bySchema = [];

	/**
	 * The schema applied last.
	 *
	 * @var string
	 */
	public string $current = '';

	/**
	 * Every save, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Every read, as [schema, offset].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	public array $reads = [];

	/**
	 * Uuids whose save fails.
	 *
	 * @var array<int, string>
	 */
	public array $failingSaves = [];

	/**
	 * Schemas whose read throws.
	 *
	 * @var array<int, string>
	 */
	public array $failingReads = [];

	/**
	 * How many applies succeed before the rest answer false; null for all.
	 *
	 * @var int|null
	 */
	public ?int $appliesLeft = null;

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The step over the fake store; $openRegister false makes the container refuse.
	 *
	 * @param bool $openRegister Whether OpenRegister resolves.
	 *
	 * @return BackfillCmsPortalReference
	 */
	private function step(bool $openRegister = true): BackfillCmsPortalReference {
		$store = new class($this) {
			/**
			 * @param BackfillCmsPortalReferenceEdgesTest $test The test holding the rows.
			 */
			public function __construct(private BackfillCmsPortalReferenceEdgesTest $test) {
			}

			/**
			 * @param array<string, mixed> $config Paging.
			 * @param mixed ...$flags RBAC and multitenancy.
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], mixed ...$flags): array {
				$schema = $this->test->current;
				if (in_array($schema, $this->test->failingReads, true) === true) {
					throw new RuntimeException('schema ' . $schema . ' is not imported');
				}

				$this->test->reads[] = [$schema, (int)$config['offset']];

				return array_slice($this->test->bySchema[$schema] ?? [], (int)$config['offset'], (int)$config['limit']);
			}

			/**
			 * @param mixed ...$args Object, register, schema, uuid and flags.
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed ...$args): array {
				if (in_array($args['uuid'], $this->test->failingSaves, true) === true) {
					throw new RuntimeException('save refused');
				}

				$this->test->saves[] = $args;
				return $args['object'];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function () use ($store, $openRegister): object {
				if ($openRegister === false) {
					throw new RuntimeException('OpenRegister is not installed');
				}

				return $store;
			}
		);

		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(
			function (object $service, string $schemaSlug): bool {
				if ($this->appliesLeft !== null) {
					if ($this->appliesLeft === 0) {
						return false;
					}

					$this->appliesLeft--;
				}

				$this->current = $schemaSlug;
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new BackfillCmsPortalReference($container, $context, $logger);
	}//end step()

	/**
	 * Run the step, returning its info and warning lines.
	 *
	 * @param bool $openRegister Whether OpenRegister resolves.
	 *
	 * @return array<int, string>
	 */
	private function runStep(bool $openRegister = true): array {
		$lines  = [];
		$output = $this->createMock(IOutput::class);
		$record = static function (string $line) use (&$lines): void {
			$lines[] = $line;
		};
		$output->method('info')->willReturnCallback($record);
		$output->method('warning')->willReturnCallback($record);
		$this->step(openRegister: $openRegister)->run($output);

		return $lines;
	}//end runStep()

	/**
	 * Without OpenRegister the step says so and reads nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsAssigned(): void {
		$this->bySchema = ['portal' => [['slug' => 'gemeente']], 'page' => [['@self' => ['uuid' => 'a1']]]];

		self::assertSame(['BackfillCmsPortalReference: OpenRegister is not available, nothing assigned.'], $this->runStep(openRegister: false));
		self::assertSame([], $this->reads);
		self::assertSame('Give existing site content its portal', $this->step()->getName());
	}//end testWithoutOpenRegisterNothingIsAssigned()

	/**
	 * The uuid comes from @self.uuid, @self.id or id; an object without one,
	 * and one whose save fails, are counted as still without a portal.
	 *
	 * @return void
	 */
	public function testObjectsThatCannotBeWrittenAreCounted(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['title' => 'Entity', '@self' => ['id' => 'e1']];
			}
		};
		$this->bySchema = [
			'portal' => [['slug' => 'gemeente'], ['slug' => ''], 'not a row'],
			'menu'   => [['title' => 'Root', 'id' => 'm1'], $entity],
			'page'   => [['title' => 'No uuid'], ['title' => 'Broken', '@self' => ['uuid' => 'p-broken']], ['title' => 'Blank portal', 'portal' => '  ', '@self' => ['uuid' => 'p2']]],
		];
		$this->failingSaves = ['p-broken'];

		$lines = $this->runStep();

		self::assertSame(['m1', 'e1', 'p2'], array_column($this->saves, 'uuid'));
		self::assertSame(['menu', 'menu', 'page'], array_column($this->saves, 'schema'));
		self::assertSame(['title' => 'Entity', 'portal' => 'gemeente'], $this->saves[1]['object']);
		self::assertFalse($this->saves[0]['_rbac']);
		self::assertFalse($this->saves[0]['_multitenancy']);
		self::assertSame('portaliq', $this->saves[0]['register']);
		self::assertSame(
			[
				'BackfillCmsPortalReference: assigned a portal to 3 objects, 2 still without one.',
				'BackfillCmsPortalReference: 2 objects have no portal and are not served. Assign them in the admin.',
			],
			$lines
		);
		self::assertSame([['Portaliq: content could not be given its portal', ['uuid' => 'p-broken', 'schema' => 'page', 'reason' => 'save refused']]], $this->warnings);
	}//end testObjectsThatCannotBeWrittenAreCounted()

	/**
	 * A schema that cannot be read is skipped; content is read past one page.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaIsSkippedAndPagesAreFollowed(): void {
		$this->bySchema = ['portal' => [['slug' => 'gemeente']], 'glossaryTerm' => []];
		for ($i = 1; $i <= 120; $i++) {
			$this->bySchema['page'][] = ['portal' => 'gemeente', '@self' => ['uuid' => 'p' . $i]];
		}

		$this->failingReads = ['menu'];

		$lines = $this->runStep();

		self::assertSame([['portal', 0], ['page', 0], ['page', 100], ['glossaryTerm', 0], ['media', 0]], $this->reads);
		self::assertSame(['BackfillCmsPortalReference: assigned a portal to 0 objects, 0 still without one.'], $lines);
	}//end testAnUnreadableSchemaIsSkippedAndPagesAreFollowed()

	/**
	 * When the schema cannot be applied the content is not read, and an object
	 * whose schema cannot be applied for the write keeps no portal.
	 *
	 * @return void
	 */
	public function testASchemaThatCannotBeAppliedIsNotWritten(): void {
		$this->bySchema = ['portal' => [['slug' => 'gemeente']], 'menu' => [['@self' => ['uuid' => 'm1']]]];
		$this->appliesLeft = 2;

		$lines = $this->runStep();

		self::assertSame([['portal', 0], ['menu', 0]], $this->reads);
		self::assertSame([], $this->saves);
		self::assertSame('BackfillCmsPortalReference: assigned a portal to 0 objects, 1 still without one.', $lines[0]);
	}//end testASchemaThatCannotBeAppliedIsNotWritten()
}//end class
