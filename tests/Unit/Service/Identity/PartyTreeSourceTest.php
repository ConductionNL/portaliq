<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PartyTreeSource;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * How portaliq calls OpenRegister's tree read: find the party by identity,
 * walk below it, RBAC off as on every portal read.
 *
 * @covers \OCA\Portaliq\Service\Identity\PartyTreeSource
 *
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
 */
class PartyTreeSourceTest extends TestCase {

	/**
	 * Calls OpenRegister received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The part of an OpenRegister object entity the source uses.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object The entity.
	 */
	public static function entity(string $uuid): object {
		return new class($uuid) {
			public function __construct(private string $uuid) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end entity()

	/**
	 * An OpenRegister object service double.
	 *
	 * @param array<int, mixed> $rows What findAll answers.
	 *
	 * @return object The double.
	 */
	private function objects(array $rows): object {
		$calls = &$this->calls;
		return new class($rows, $calls) {
			/**
			 * @param array<int, mixed> $rows
			 * @param array<int, array<int, mixed>> $calls
			 */
			public function __construct(private array $rows, private array &$calls) {
			}

			public function setRegister(string $register): void {
				$this->calls[] = ['setRegister', $register];
			}

			public function setSchema(string $schema): void {
				$this->calls[] = ['setSchema', $schema];
			}

			/**
			 * @param array<string, mixed> $config
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				$this->calls[] = ['findAll', $config, $_rbac, $_multitenancy];
				return $this->rows;
			}

			public function find(string $id, string $register, string $schema, bool $_rbac, bool $_multitenancy): object {
				$this->calls[] = ['find', $id, $register, $schema, $_rbac, $_multitenancy];
				return PartyTreeSourceTest::entity(uuid: $id);
			}
		};
	}//end objects()

	/**
	 * An OpenRegister tree service double.
	 *
	 * @param bool $fails Whether the read throws.
	 *
	 * @return object The double.
	 */
	private function trees(bool $fails = false): object {
		$calls = &$this->calls;
		return new class($fails, $calls) {
			/**
			 * @param array<int, array<int, mixed>> $calls
			 */
			public function __construct(private bool $fails, private array &$calls) {
			}

			/**
			 * @param string[] $fields
			 * @return array<string, mixed>
			 */
			public function tree(object $root, string $direction, ?int $depth, array $fields, bool $_rbac): array {
				$this->calls[] = ['tree', $root->getUuid(), $direction, $depth, $fields, $_rbac];
				if ($this->fails === true) {
					throw new RuntimeException('table gone');
				}

				return ['nodes' => [['uuid' => $root->getUuid(), 'level' => 0]], 'truncated' => false, 'truncatedBy' => null];
			}
		};
	}//end trees()

	/**
	 * The source over a container holding the given services.
	 *
	 * @param array<string, object> $services Class name => service.
	 *
	 * @return PartyTreeSource The source.
	 */
	private function source(array $services): PartyTreeSource {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new class('missing') extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $services[$id];
			}
		);

		return new PartyTreeSource($container, $this->createMock(LoggerInterface::class));
	}//end source()

	/**
	 * Both OpenRegister services.
	 *
	 * @param array<int, mixed> $rows  What the lookup finds.
	 * @param bool              $fails Whether the tree read throws.
	 *
	 * @return array<string, object>
	 */
	private function services(array $rows, bool $fails = false): array {
		return [
			'OCA\\OpenRegister\\Service\\ObjectService' => $this->objects(rows: $rows),
			'OCA\\OpenRegister\\Service\\Relation\\ObjectTreeService' => $this->trees(fails: $fails),
		];
	}//end services()

	public function testThePartyIsFoundByIdentityAndWalkedWithoutRbac(): void {
		$tree = $this->source(services: $this->services(rows: [self::entity(uuid: 'uuid-holding')]))
			->below(register: 'dossiq', schema: 'kvkCompany', identityField: 'kvkNumber', identity: '10000000', maxDepth: 3);

		$this->assertSame('uuid-holding', $tree['nodes'][0]['uuid']);
		$this->assertSame(['setRegister', 'dossiq'], $this->calls[0]);
		$this->assertSame(['setSchema', 'kvkCompany'], $this->calls[1]);
		$this->assertSame(['findAll', ['filters' => ['kvkNumber' => '10000000'], 'limit' => 2, 'offset' => 0], false, false], $this->calls[2]);
		$this->assertSame(['tree', 'uuid-holding', 'descendants', 3, ['kvkNumber'], false], $this->calls[3]);
	}//end testThePartyIsFoundByIdentityAndWalkedWithoutRbac()

	public function testARenderedRowIsReadBackAsTheEntity(): void {
		$this->source(services: $this->services(rows: [['@self' => ['id' => 'uuid-holding'], 'kvkNumber' => '10000000']]))
			->below(register: 'dossiq', schema: 'kvkCompany', identityField: 'kvkNumber', identity: '10000000', maxDepth: 3);

		$this->assertSame(['find', 'uuid-holding', 'dossiq', 'kvkCompany', false, false], $this->calls[3]);
		$this->assertSame('tree', $this->calls[4][0]);
	}//end testARenderedRowIsReadBackAsTheEntity()

	public function testNoPartyObjectMeansNoTree(): void {
		$source = $this->source(services: $this->services(rows: []));

		$this->assertNull($source->below(register: 'dossiq', schema: 'kvkCompany', identityField: 'kvkNumber', identity: '10000000', maxDepth: 3));
	}//end testNoPartyObjectMeansNoTree()

	public function testTwoPartiesWithOneIdentityAreNotGuessedBetween(): void {
		$source = $this->source(services: $this->services(rows: [self::entity(uuid: 'one'), self::entity(uuid: 'two')]));

		$this->assertNull($source->below(register: 'dossiq', schema: 'kvkCompany', identityField: 'kvkNumber', identity: '10000000', maxDepth: 3));
	}//end testTwoPartiesWithOneIdentityAreNotGuessedBetween()

	public function testAnOpenRegisterWithoutTheTreeReadMeansNoTree(): void {
		$services = $this->services(rows: [self::entity(uuid: 'uuid-holding')]);
		unset($services['OCA\\OpenRegister\\Service\\Relation\\ObjectTreeService']);

		$this->assertNull($this->source(services: $services)->below(register: 'dossiq', schema: 'kvkCompany', identityField: 'kvkNumber', identity: '10000000', maxDepth: 3));
	}//end testAnOpenRegisterWithoutTheTreeReadMeansNoTree()

	public function testAFailedReadIsAPartialTreeNotAnEmptyOne(): void {
		$tree = $this->source(services: $this->services(rows: [self::entity(uuid: 'uuid-holding')], fails: true))
			->below(register: 'dossiq', schema: 'kvkCompany', identityField: 'kvkNumber', identity: '10000000', maxDepth: 3);

		$this->assertTrue($tree['truncated']);
		$this->assertSame('unreadable', $tree['truncatedBy']);
	}//end testAFailedReadIsAPartialTreeNotAnEmptyOne()
}//end class
