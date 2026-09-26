<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */
class GuardianAudienceFixtureReaderTest extends TestCase {

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
				if (isset($filters['guardianRef']) === true) {
					return array_values(array_filter($this->rows, fn (array $r): bool => ($r['guardianRef'] ?? null) === $filters['guardianRef']));
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

	public function testReturnsEmptyAudienceWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new GuardianAudienceFixtureReader($container, $this->createMock(LoggerInterface::class));
		$audience = $reader->resolveAudience('guardian-anna-devries');

		$this->assertSame([], $audience['groupRefs']);
	}//end testReturnsEmptyAudienceWhenOpenRegisterUnavailable()

	public function testResolvesAMatchingRow(): void {
		$os = $this->fakeObjectService([['guardianRef' => 'guardian-anna-devries', 'schoolRef' => 'school-a', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-1']]]);
		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));
		$audience = $reader->resolveAudience('guardian-anna-devries');

		$this->assertSame('school-a', $audience['schoolRef']);
		$this->assertSame(['groep-5a'], $audience['groupRefs']);
	}//end testResolvesAMatchingRow()

	public function testGuardianReachesGroup(): void {
		$os = $this->fakeObjectService([['guardianRef' => 'guardian-anna-devries', 'groupRefs' => ['groep-5a']]]);
		$reader = new GuardianAudienceFixtureReader($this->container($os), $this->createMock(LoggerInterface::class));

		$this->assertTrue($reader->guardianReachesGroup('guardian-anna-devries', 'groep-5a'));
		$this->assertFalse($reader->guardianReachesGroup('guardian-anna-devries', 'groep-9z'));
		$this->assertFalse($reader->guardianReachesGroup('guardian-unknown', 'groep-5a'));
	}//end testGuardianReachesGroup()
}//end class
