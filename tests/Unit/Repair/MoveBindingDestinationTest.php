<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\MoveBindingDestination;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * submit-creates-the-case-directly 1.1: the repair step moves a binding's
 * `caseRegister`/`caseSchema` into `destination` and drops the old fields;
 * a binding that names no register is counted, never guessed; a second run
 * writes nothing.
 *
 * @covers \OCA\Portaliq\Repair\MoveBindingDestination
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#1.1
 */
class MoveBindingDestinationTest extends TestCase {

	public function testTheCasePairMovesIntoTheDestinationAndTheOldFieldsGo(): void {
		$moved = $this->step(store: $this->store(rows: []))->movedBinding(row: [
			'portal' => 'gemeente-x',
			'caseRegister' => 'dossiq',
			'caseSchema' => 'case',
			'deliverTo' => 'case',
			'destination' => ['subjectField' => 'portalSubject'],
			'@self' => ['uuid' => 'b-1'],
		]);

		$this->assertSame(['subjectField' => 'portalSubject', 'register' => 'dossiq', 'schema' => 'case'], $moved['destination']);
		$this->assertArrayNotHasKey('caseRegister', $moved);
		$this->assertArrayNotHasKey('caseSchema', $moved);
		$this->assertArrayNotHasKey('deliverTo', $moved);
		$this->assertArrayNotHasKey('@self', $moved);
	}//end testTheCasePairMovesIntoTheDestinationAndTheOldFieldsGo()

	public function testABindingWithADestinationOrWithoutAPairIsLeftAlone(): void {
		$step = $this->step(store: $this->store(rows: []));

		$this->assertNull($step->movedBinding(row: ['destination' => ['register' => 'pipelinq', 'schema' => 'lead'], 'caseRegister' => 'dossiq', 'caseSchema' => 'case']));
		$this->assertNull($step->movedBinding(row: ['deliverTo' => 'wooRequest']));
	}//end testABindingWithADestinationOrWithoutAPairIsLeftAlone()

	public function testARunSavesOnlyWhatMovesAndNamesTheBindingsWithoutADestination(): void {
		$store = $this->store(rows: [
			['portal' => 'x', 'caseRegister' => 'dossiq', 'caseSchema' => 'case', '@self' => ['uuid' => 'b-1']],
			['portal' => 'x', 'deliverTo' => 'wooRequest', '@self' => ['uuid' => 'b-2']],
			['portal' => 'x', 'intakeKind' => 'external', '@self' => ['uuid' => 'b-3']],
			['portal' => 'x', 'destination' => ['register' => 'dossiq', 'schema' => 'case'], '@self' => ['uuid' => 'b-4']],
		]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('moved the destination of 1 form bindings'));
		$output->expects($this->once())->method('warning')->with($this->stringContains('b-2'));

		$this->step(store: $store)->run($output);

		$this->assertCount(1, $store->saves);
		$this->assertSame('b-1', $store->saves[0]['uuid']);
		$this->assertSame('portalFormBinding', $store->saves[0]['schema']);

		// A second run moves nothing more.
		$again = $this->createMock(IOutput::class);
		$again->expects($this->once())->method('info')->with($this->stringContains('of 0 form bindings'));
		$this->step(store: $store)->run($again);
		$this->assertCount(1, $store->saves);
	}//end testARunSavesOnlyWhatMovesAndNamesTheBindingsWithoutADestination()

	public function testWithoutOpenRegisterTheStepDoesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new MoveBindingDestination($container, $this->createMock(PortalRegisterContext::class), $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutOpenRegisterTheStepDoesNothing()

	/**
	 * The step over a store.
	 *
	 * @param object $store The store.
	 *
	 * @return MoveBindingDestination
	 */
	private function step(object $store): MoveBindingDestination {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return new MoveBindingDestination($container, $context, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * A store of bindings that pages like OpenRegister and records each save.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored bindings.
	 *
	 * @return object
	 */
	private function store(array $rows): object {
		return new class($rows) {
			public array $saves = [];

			public function __construct(public array $rows) {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saves[] = ['uuid' => $uuid, 'schema' => $schema, 'object' => $object];
				foreach ($this->rows as $index => $row) {
					if (($row['@self']['uuid'] ?? null) === $uuid) {
						$this->rows[$index] = $object + ['@self' => $row['@self']];
					}
				}

				return $object;
			}
		};
	}//end store()
}//end class
