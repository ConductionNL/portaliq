<?php

/**
 * TrafficStoresGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use JsonSerializable;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficRecordingStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * What the traffic event store and the session recording store read, purge
 * and log, per store world: no OpenRegister, a raw purge, a raw purge that
 * fails, the ordinary path over rows of every shape, and a read that fails.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficEventStore
 * @covers \OCA\Portaliq\Service\Traffic\TrafficRecordingStore
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
 * @uses   \OCA\Portaliq\Service\RowAsArray
 */
class TrafficStoresGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-stores.json';

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * What the store and the logger were asked in the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $asked = [];

	/**
	 * Every store answer matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryStoreAnswerMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $worldName => $world) {
			foreach ($this->calls() as $callName => $call) {
				$this->asked = [];
				$answer      = $call($this->container(world: $world), $this->logger());
				$asked       = (string)json_encode($this->asked);
				$this->assertSame(expected: 0, actual: preg_match('/"expires":\{"lt":"(?!\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z")/', $asked));
				$asked = (string)preg_replace('/"expires":\{"lt":"[^"]*"/', '"expires":{"lt":"(now)"', $asked);
				$actual[$worldName . ' | ' . $callName] = ['answer' => $answer, 'asked' => json_decode($asked, true)];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $case => $answer) {
			$this->assertSame(expected: $answer, actual: json_decode((string)json_encode($actual[$case] ?? null), true), message: $case);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testEveryStoreAnswerMatchesTheGolden()

	/**
	 * The store calls, by name.
	 *
	 * @return array<string, callable(ContainerInterface, LoggerInterface): mixed>
	 */
	private function calls(): array {
		return [
			'events purge' => fn (ContainerInterface $c, LoggerInterface $l): int => $this->events(container: $c, logger: $l)->purgeExpired(),
			'events between' => fn (ContainerInterface $c, LoggerInterface $l): array => $this->events(container: $c, logger: $l)->eventsBetween(
				portal: 'zuid',
				from: '2026-11-01',
				to: '2026-11-02'
			),
			'recordings purge' => fn (ContainerInterface $c, LoggerInterface $l): int => $this->recordings(container: $c, logger: $l)->purgeExpired(),
			'recording find' => fn (ContainerInterface $c, LoggerInterface $l): ?array => $this->recordings(container: $c, logger: $l)->find(
				portal: 'zuid',
				recordingId: 'r1'
			),
		];
	}//end calls()

	/**
	 * The store worlds, by name: null is no OpenRegister.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private function worlds(): array {
		$entity = new class implements JsonSerializable {
			/**
			 * An entity that serialises to a row.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return ['@self' => ['uuid' => 'u-entity'], 'portal' => 'zuid', 'recordingId' => 'r1'];
			}
		};
		$plainObject = new class implements JsonSerializable {
			/**
			 * An entity that serialises to an object.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				$object     = new stdClass();
				$object->id = 'u-object';

				return $object;
			}
		};
		$rows = [
			['@self' => ['uuid' => ' u-padded '], 'portal' => 'zuid', 'recordingId' => 'r0'],
			['uuid' => 'u-flat'],
			['@self' => ['id' => 'u-self-id']],
			['id' => 'u-id'],
			[],
			$entity,
			$plainObject,
			'text',
		];

		return [
			'no object service' => null,
			'raw purge' => ['raw' => 4, 'rows' => $rows],
			'raw purge fails' => ['raw' => new RuntimeException('raw down'), 'rows' => $rows],
			'ordinary path' => ['rows' => $rows],
			'read fails' => ['rows' => new RuntimeException('read down')],
			'delete fails' => ['rows' => $rows, 'delete' => new RuntimeException('delete down')],
		];
	}//end worlds()

	/**
	 * The event store over the container.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return TrafficEventStore
	 */
	private function events(ContainerInterface $container, LoggerInterface $logger): TrafficEventStore {
		return new TrafficEventStore(container: $container, logger: $logger, context: $this->context());
	}//end events()

	/**
	 * The recording store over the container.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return TrafficRecordingStore
	 */
	private function recordings(ContainerInterface $container, LoggerInterface $logger): TrafficRecordingStore {
		return new TrafficRecordingStore(container: $container, logger: $logger, context: $this->context());
	}//end recordings()

	/**
	 * A context that always points the service at the schema.
	 *
	 * @return PortalRegisterContext
	 */
	private function context(): PortalRegisterContext {
		$context = $this->createMock(originalClassName: PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return $context;
	}//end context()

	/**
	 * A logger whose lines land in the store calls.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$asked  = &$this->asked;
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$asked, $level): void {
					$asked[] = [$level => [$message, $context]];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A container whose object service answers as the world says.
	 *
	 * @param array<string, mixed>|null $world The world, or null for no service.
	 *
	 * @return ContainerInterface
	 */
	private function container(?array $world): ContainerInterface {
		$asked   = &$this->asked;
		$service = null;
		if ($world !== null && array_key_exists('raw', $world) === true) {
			$service = new class($world, $asked) {
				/**
				 * @param array<string, mixed> $world The world.
				 * @param array<int, mixed>    $asked What the store was asked.
				 */
				public function __construct(private array $world, private array &$asked) {
				}

				/**
				 * @param string $register The register.
				 * @param string $schema   The schema.
				 *
				 * @return int
				 */
				public function purgeExpiredObjectsRaw(string $register, string $schema): int {
					$this->asked[] = ['purgeExpiredObjectsRaw' => [$register, $schema]];
					if ($this->world['raw'] instanceof \Throwable) {
						throw $this->world['raw'];
					}

					return $this->world['raw'];
				}

				/**
				 * @param array<string, mixed> $config        The read.
				 * @param bool                 $_rbac         Access rules.
				 * @param bool                 $_multitenancy Tenancy rules.
				 *
				 * @return mixed
				 */
				public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
					$this->asked[] = ['findAll' => [$config, $_rbac, $_multitenancy]];

					return $this->world['rows'];
				}
			};
		} elseif ($world !== null) {
			$service = new class($world, $asked) {
				/**
				 * @param array<string, mixed> $world The world.
				 * @param array<int, mixed>    $asked What the store was asked.
				 */
				public function __construct(private array $world, private array &$asked) {
				}

				/**
				 * @param array<string, mixed> $config        The read.
				 * @param bool                 $_rbac         Access rules.
				 * @param bool                 $_multitenancy Tenancy rules.
				 *
				 * @return mixed
				 */
				public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
					$this->asked[] = ['findAll' => [$config, $_rbac, $_multitenancy]];
					if ($this->world['rows'] instanceof \Throwable) {
						throw $this->world['rows'];
					}

					return $this->world['rows'];
				}

				/**
				 * @param string $uuid          The uuid.
				 * @param mixed  $register      The register.
				 * @param mixed  $schema        The schema.
				 * @param bool   $_rbac         Access rules.
				 * @param bool   $_multitenancy Tenancy rules.
				 *
				 * @return bool
				 */
				public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): bool {
					$this->asked[] = ['deleteObject' => [$uuid, $register, $schema, $_rbac, $_multitenancy]];
					if (($this->world['delete'] ?? null) instanceof \Throwable) {
						throw $this->world['delete'];
					}

					return true;
				}
			};
		}//end if

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === self::OS && $service !== null) {
					return $service;
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		return $container;
	}//end container()
}//end class
