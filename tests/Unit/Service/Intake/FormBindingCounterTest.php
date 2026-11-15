<?php

/**
 * Unit tests for FormBindingCounter.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\FormBindingCounter;
use OCA\Portaliq\Service\Intake\PortalFormAvailability;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The status panel's counter: the binding read by id, its period counted
 * over the destination exactly as the submit path's limit check counts it.
 *
 * @covers \OCA\Portaliq\Service\Intake\FormBindingCounter
 * @uses   \OCA\Portaliq\Service\Intake\PortalFormAvailability
 * @uses   \OCA\Portaliq\Service\Intake\DestinationMapping
 */
class FormBindingCounterTest extends TestCase {

	/**
	 * The find calls the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $found = [];

	/**
	 * The count calls the writer received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $counted = [];

	/**
	 * A counter whose object service answers $answer to find(), and whose
	 * destination holds $count objects.
	 *
	 * @param mixed $answer The binding the object service returns, or a Throwable to throw.
	 * @param int   $count  The destination's count.
	 *
	 * @return FormBindingCounter
	 */
	private function counter(mixed $answer, int $count = 0): FormBindingCounter {
		$service = new class($answer, $this->found) {
			/**
			 * @param mixed $answer The answer.
			 * @param array $found  The recorded calls.
			 */
			public function __construct(private mixed $answer, private array &$found) {
			}

			/**
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->found[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('countObjects')->willReturnCallback(
			function (string $register, string $schema, array $filters = []) use ($count): int {
				$this->counted[] = ['register' => $register, 'schema' => $schema, 'filters' => $filters];
				return $count;
			}
		);

		return new FormBindingCounter(
			container: $container,
			availability: new PortalFormAvailability(mapping: new DestinationMapping(), objects: $writer)
		);
	}//end counter()

	/**
	 * A binding to the dossiq case schema with this limit.
	 *
	 * @param array<string, mixed>|null $limit The limit, or null for none.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(?array $limit): array {
		$availability = [];
		if ($limit !== null) {
			$availability['limit'] = $limit;
		}

		return ['id' => 'b-1', 'destination' => ['register' => 'dossiq', 'schema' => 'case'], 'availability' => $availability];
	}//end binding()

	/**
	 * 14 November 2026, noon in Amsterdam.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-14 12:00', new DateTimeZone('Europe/Amsterdam'));
	}//end now()

	/**
	 * A monthly limit reads the month's count against it.
	 *
	 * @return void
	 */
	public function testAMonthlyLimitCountsTheMonth(): void {
		$result = $this->counter(answer: $this->binding(limit: ['count' => 500, 'per' => 'month']), count: 41)
			->forBinding(id: ' b-1 ', now: $this->now());

		$this->assertSame(['found' => true, 'limited' => true, 'count' => 41, 'limit' => 500, 'per' => 'month'], $result);
		$this->assertSame([['id' => 'b-1', 'register' => 'portaliq', 'schema' => 'portalFormBinding', 'rbac' => false, 'multitenancy' => false]], $this->found);
		$this->assertCount(1, $this->counted);
		$this->assertSame('dossiq', $this->counted[0]['register']);
		$this->assertSame('case', $this->counted[0]['schema']);
		$this->assertSame('2026-10-31 23:00:00', $this->counted[0]['filters']['@self']['created']['gte']);
	}//end testAMonthlyLimitCountsTheMonth()

	/**
	 * A binding read as an entity is serialised first.
	 *
	 * @return void
	 */
	public function testAnEntityIsReadThroughItsSerialisation(): void {
		$entity = new class($this->binding(limit: ['count' => 150, 'per' => 'total'])) implements \JsonSerializable {
			/**
			 * @param array $data The row.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

		$result = $this->counter(answer: $entity, count: 150)->forBinding(id: 'b-1', now: $this->now());

		$this->assertSame(['found' => true, 'limited' => true, 'count' => 150, 'limit' => 150, 'per' => 'total'], $result);
		$this->assertSame([], $this->counted[0]['filters']);
	}//end testAnEntityIsReadThroughItsSerialisation()

	/**
	 * Without a limit nothing is counted.
	 *
	 * @return void
	 */
	public function testWithoutALimitNothingIsCounted(): void {
		$result = $this->counter(answer: $this->binding(limit: null), count: 9)->forBinding(id: 'b-1', now: $this->now());

		$this->assertSame(['found' => true, 'limited' => false, 'count' => null, 'limit' => null, 'per' => null], $result);
		$this->assertSame([], $this->counted);
	}//end testWithoutALimitNothingIsCounted()

	/**
	 * An unreadable binding, an empty id and a non-object answer are not found.
	 *
	 * @return void
	 */
	public function testAnUnreadableBindingIsNotFound(): void {
		$missing = ['found' => false, 'limited' => false, 'count' => null, 'limit' => null, 'per' => null];

		$this->assertSame($missing, $this->counter(answer: new RuntimeException('gone'))->forBinding(id: 'b-1'));
		$this->assertSame($missing, $this->counter(answer: 'not a row')->forBinding(id: 'b-1'));

		$this->found = [];
		$this->assertSame($missing, $this->counter(answer: $this->binding(limit: null))->forBinding(id: '  '));
		$this->assertSame([], $this->found);
	}//end testAnUnreadableBindingIsNotFound()

	/**
	 * Without OpenRegister the binding cannot be read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheBindingIsNotFound(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));
		$counter = new FormBindingCounter(
			container: $container,
			availability: new PortalFormAvailability(mapping: new DestinationMapping())
		);

		$this->assertFalse($counter->forBinding(id: 'b-1')['found']);
	}//end testWithoutOpenRegisterTheBindingIsNotFound()
}//end class
