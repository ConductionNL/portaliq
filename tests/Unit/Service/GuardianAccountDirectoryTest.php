<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAccountDirectory;
use OCA\Portaliq\Service\LeafGuardianAudienceReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guardians who can be reached are the active portal accounts of the
 * parent audience.
 *
 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T1
 */
class GuardianAccountDirectoryTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Only active parent accounts are listed, each once, and the read asks
	 * OpenRegister for exactly those.
	 */
	public function testListsTheActiveParentAccountsOnce(): void {
		$os = new class {
			/** @var array<int, array<string, mixed>> */
			public array $configs = [];

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->configs[] = $config;
				return [
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'fatima'],
					['audience' => 'parent', 'status' => 'pending', 'subjectRef' => 'pending-one'],
					['audience' => 'supplier', 'status' => 'active', 'subjectRef' => 'a-supplier'],
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'fatima'],
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => ''],
					new class {
						/** @return array<string, string> */
						public function jsonSerialize(): array {
							return ['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'jan'];
						}//end jsonSerialize()
					},
				];
			}//end findAll()
		};

		$directory = new GuardianAccountDirectory($this->container($os), $this->createMock(LoggerInterface::class), $this->createMock(LeafGuardianAudienceReader::class));

		$this->assertSame(['fatima', 'jan'], $directory->activeGuardianRefs());
		$this->assertSame(['audience' => 'parent', 'status' => 'active'], $os->configs[0]['filters']);
	}//end testListsTheActiveParentAccountsOnce()

	/**
	 * Without OpenRegister nobody is listed, and nothing throws.
	 */
	public function testWithoutOpenRegisterNobodyIsListed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$this->assertSame([], (new GuardianAccountDirectory($container, $this->createMock(LoggerInterface::class), $this->createMock(LeafGuardianAudienceReader::class)))->activeGuardianRefs());
	}//end testWithoutOpenRegisterNobodyIsListed()

	/**
	 * The guardians whose school app audience matches are returned, the
	 * excluded ones are never resolved.
	 *
	 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T2
	 */
	public function testMatchesTheTargetAgainstTheSchoolAppAudience(): void {
		$os = new class {
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, string>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return [
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'fatima'],
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'other-school'],
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'no-children'],
					['audience' => 'parent', 'status' => 'active', 'subjectRef' => 'fixture-guardian'],
				];
			}//end findAll()
		};
		$resolved = [];
		$leaf = $this->createMock(LeafGuardianAudienceReader::class);
		$leaf->method('resolveAudience')->willReturnCallback(
			static function (string $subjectRef) use (&$resolved): ?array {
				$resolved[] = $subjectRef;
				return match ($subjectRef) {
					'fatima' => ['schoolRef' => 'school-w', 'groupRefs' => ['groep-7'], 'childRefs' => ['vera'], 'photoConsent' => []],
					'other-school' => ['schoolRef' => 'school-x', 'groupRefs' => ['groep-3'], 'childRefs' => ['kim'], 'photoConsent' => []],
					default => null,
				};
			}
		);
		$directory = new GuardianAccountDirectory($this->container($os), $this->createMock(LoggerInterface::class), $leaf);

		$this->assertSame(['fatima'], $directory->guardiansMatching(['groupRefs' => ['groep-7']], ['fixture-guardian']));
		$this->assertSame(['fatima'], $directory->guardiansMatching(['schoolRef' => 'school-w']));
		$this->assertSame([], $directory->guardiansMatching(['childRefs' => ['nobody']]));
		$this->assertNotContains('fixture-guardian', array_slice($resolved, 0, 3));
	}//end testMatchesTheTargetAgainstTheSchoolAppAudience()

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return $container;
	}//end container()
}//end class
