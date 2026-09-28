<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Portaliq\Service\ShillinqContributionRaiser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The duck-typed shillinq call (activity-offer-contract-fix): absent shillinq
 * and each of its failures map to one answer, a good answer passes through.
 *
 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
 */
class ShillinqContributionRaiserTest extends TestCase {
	/**
	 * A raiser whose container hands out the given service under an existing
	 * class name.
	 *
	 * @param object|null $service The service, or null when the container has none.
	 * @param string $class The class name the raiser looks for.
	 *
	 * @return ShillinqContributionRaiser
	 */
	private function raiser(?object $service, string $class = \stdClass::class): ShillinqContributionRaiser {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($service !== null);
		$container->method('get')->willReturn($service);

		return new ShillinqContributionRaiser($container, $this->createMock(LoggerInterface::class), $class);
	}//end raiser()

	/**
	 * A service double whose raise() runs the given callable.
	 *
	 * @param callable $raise `(array $payload) => mixed`.
	 *
	 * @return object
	 */
	private function service(callable $raise): object {
		return new class($raise) {
			/**
			 * @param callable $raise The behaviour.
			 */
			public function __construct(private $raise) {
			}

			/**
			 * @param array<string, mixed> $payload The payload.
			 *
			 * @return mixed
			 */
			public function raise(array $payload): mixed {
				return ($this->raise)($payload);
			}
		};
	}//end service()

	/**
	 * Without shillinq, and with the real class name on an instance that does
	 * not ship it, the answer is `shillinq_unavailable`.
	 *
	 * @return void
	 */
	public function testAbsentShillinqIsUnavailable(): void {
		$this->assertSame(['error' => 'shillinq_unavailable'], $this->raiser(null)->raise([]));
		$this->assertSame(
			['error' => 'shillinq_unavailable'],
			(new ShillinqContributionRaiser($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class)))->raise([])
		);
	}//end testAbsentShillinqIsUnavailable()

	/**
	 * Each shillinq failure maps to one answer, and a good answer passes.
	 *
	 * @return void
	 */
	public function testShillinqAnswersAreMapped(): void {
		$invalid = $this->service(static fn () => throw new InvalidArgumentException('A contribution needs a description.'));
		$forbidden = $this->service(static fn () => throw new RuntimeException('403 you may not raise contributions'));
		$broken = $this->service(static fn () => throw new RuntimeException('database gone'));
		$typeError = $this->service(static fn () => throw new \TypeError('raise(): Argument #1 must be of type array'));
		$odd = $this->service(static fn () => ['no' => 'results']);
		$good = $this->service(static fn (array $payload) => ['batchId' => 'ctb-1', 'results' => [['index' => 0, 'status' => 'raised']]]);

		$this->assertSame('invalid_charge', $this->raiser($invalid)->raise([])['error']);
		$this->assertSame(['error' => 'forbidden'], $this->raiser($forbidden)->raise([]));
		$this->assertSame(['error' => 'raise_failed'], $this->raiser($broken)->raise([]));
		$this->assertSame(['error' => 'raise_failed'], $this->raiser($typeError)->raise([]));
		$this->assertSame(['error' => 'raise_failed'], $this->raiser($odd)->raise([]));
		$this->assertSame('ctb-1', $this->raiser($good)->raise(['kind' => 'activity'])['batchId']);
	}//end testShillinqAnswersAreMapped()
}//end class
