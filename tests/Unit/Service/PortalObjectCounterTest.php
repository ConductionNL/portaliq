<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectCounter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Counting the objects of a register and schema: a number from
 * OpenRegister's own count, and 0, never an exception, when OpenRegister is
 * missing or the count fails, with the failure logged as a warning.
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 */
class PortalObjectCounterTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	public function testTheCountIsOpenRegistersCountOnTheRegisterAndSchema(): void {
		$objectService = $this->objectService(count: 3);
		$counter = $this->counter(container: $this->container(objectService: $objectService), logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(3, $counter->countObjects(register: 'portaliq', schema: 'portal'));
		$this->assertSame(
			[['setRegister', 'portaliq'], ['setSchema', 'portal'], ['count', ['filters' => []]]],
			$objectService->calls
		);
	}

	public function testWithoutOpenRegisterTheCountIsZeroAndNothingIsLogged(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame(0, $this->counter(container: $container, logger: $logger)->countObjects(register: 'portaliq', schema: 'portal'));
	}

	public function testAFailingCountIsZeroAndLoggedAsAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Portaliq: OR count failed', ['schema' => 'portal', 'reason' => 'count broke']);
		$container = $this->container(objectService: $this->objectService(count: null));

		$this->assertSame(0, $this->counter(container: $container, logger: $logger)->countObjects(register: 'portaliq', schema: 'portal'));
	}

	/**
	 * The class that counts, over the given container and logger.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return PortalObjectCounter
	 */
	private function counter(ContainerInterface $container, LoggerInterface $logger): PortalObjectCounter {
		return new PortalObjectCounter($container, $logger);
	}

	/**
	 * A container answering the object service.
	 *
	 * @param object $objectService The object service.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(self::OS)->willReturn($objectService);
		return $container;
	}

	/**
	 * An object service that records its calls and counts, or throws on a null count.
	 *
	 * @param int|null $count The count to answer, or null to throw.
	 *
	 * @return object
	 */
	private function objectService(?int $count): object {
		return new class($count) {
			/**
			 * The calls made, in order.
			 *
			 * @var list<array{0: string, 1: mixed}>
			 */
			public array $calls = [];

			public function __construct(private readonly ?int $count) {
			}

			public function setRegister(string $register): void {
				$this->calls[] = ['setRegister', $register];
			}

			public function setSchema(string $schema): void {
				$this->calls[] = ['setSchema', $schema];
			}

			public function count(array $config): int {
				$this->calls[] = ['count', $config];
				if ($this->count === null) {
					throw new RuntimeException('count broke');
				}

				return $this->count;
			}
		};
	}
}
