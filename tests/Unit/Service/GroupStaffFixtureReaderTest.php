<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GroupStaffFixtureReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */
class GroupStaffFixtureReaderTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function fakeObjectService(array $rows): object {
		return new class($rows) {
			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'] ?? [];
				if (isset($filters['groupRef']) === true) {
					return array_values(array_filter($this->rows, fn (array $r): bool => ($r['groupRef'] ?? null) === $filters['groupRef']));
				}

				return $this->rows;
			}//end findAll()
		};
	}//end fakeObjectService()

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));
		return $container;
	}//end container()

	public function testReturnsEmptyWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new GroupStaffFixtureReader($container, $this->createMock(LoggerInterface::class));
		$this->assertSame([], $reader->staffForGroup('groep-5a'));
	}//end testReturnsEmptyWhenOpenRegisterUnavailable()

	public function testStaffForGroupResolvesAMatchingRow(): void {
		$os = $this->fakeObjectService([['groupRef' => 'groep-5a', 'staffRefs' => ['staff-leerkracht-5a']]]);
		$reader = new GroupStaffFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));

		$this->assertSame(['staff-leerkracht-5a'], $reader->staffForGroup('groep-5a'));
		$this->assertSame([], $reader->staffForGroup('groep-unknown'));
	}//end testStaffForGroupResolvesAMatchingRow()

	public function testStaffTeachesGroup(): void {
		$os = $this->fakeObjectService([['groupRef' => 'groep-5a', 'staffRefs' => ['staff-leerkracht-5a']]]);
		$reader = new GroupStaffFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));

		$this->assertTrue($reader->staffTeachesGroup('staff-leerkracht-5a', 'groep-5a'));
		$this->assertFalse($reader->staffTeachesGroup('staff-other', 'groep-5a'));
	}//end testStaffTeachesGroup()

	public function testGroupsTaughtByEnumeratesEveryMatchingRow(): void {
		$os = $this->fakeObjectService([
			['groupRef' => 'groep-5a', 'staffRefs' => ['staff-leerkracht-5a']],
			['groupRef' => 'groep-3b', 'staffRefs' => ['staff-leerkracht-3b']],
			['groupRef' => 'groep-7a', 'staffRefs' => ['staff-leerkracht-5a', 'staff-other']],
		]);
		$reader = new GroupStaffFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));

		$this->assertSame(['groep-5a', 'groep-7a'], $reader->groupsTaughtBy('staff-leerkracht-5a'));
	}//end testGroupsTaughtByEnumeratesEveryMatchingRow()
}//end class
