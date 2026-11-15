<?php

/**
 * TrafficRecordingStoreTest
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

use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\Traffic\TrafficRecordingStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A session recording is saved into its own schema outside access and
 * tenancy rules, and every way it can fail is a false, never an exception;
 * a read the register refuses or answers oddly finds nothing.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficRecordingStore
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
 * @uses   \OCA\Portaliq\Service\RowAsArray
 */
class TrafficRecordingStoreTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * What the object service was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * What reached the logger.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A recording is saved into the recording schema with its uuid, outside
	 * access and tenancy rules.
	 *
	 * @return void
	 */
	public function testARecordingIsSavedIntoItsSchemaOutsideAccessRules(): void {
		$recording = ['portal' => 'zuid', 'recordingId' => 'r1', 'events' => [1, 2]];

		$saved = $this->store(service: $this->service())->save(recording: $recording, uuid: 'u-1');

		$this->assertTrue($saved);
		$this->assertSame([['saveObject' => [$recording, 'portaliq', 'portalTrafficRecording', 'u-1', false, false]]], $this->asked);
		$this->assertSame([], $this->logged);
	}//end testARecordingIsSavedIntoItsSchemaOutsideAccessRules()

	/**
	 * Without OpenRegister nothing is saved.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsSaved(): void {
		$this->assertFalse($this->store(service: null)->save(recording: ['portal' => 'zuid'], uuid: null));
		$this->assertSame([], $this->asked);
	}//end testWithoutOpenRegisterNothingIsSaved()

	/**
	 * A context that cannot point the service at the schema stops the save
	 * and the read before the register is asked.
	 *
	 * @return void
	 */
	public function testARefusedContextStopsTheSaveAndTheRead(): void {
		$store = $this->store(service: $this->service(), contextApplies: false);

		$this->assertFalse($store->save(recording: ['portal' => 'zuid'], uuid: null));
		$this->assertNull($store->find(portal: 'zuid', recordingId: 'r1'));
		$this->assertSame([], $this->asked);
	}//end testARefusedContextStopsTheSaveAndTheRead()

	/**
	 * A failing save is a false and is logged with the portal and the reason.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndAFalse(): void {
		$store = $this->store(service: $this->service(saveFails: new RuntimeException('register down')));

		$this->assertFalse($store->save(recording: ['portal' => 'zuid'], uuid: null));
		$this->assertSame([['Portaliq: traffic recording save failed', ['portal' => 'zuid', 'reason' => 'register down']]], $this->logged);
	}//end testAFailingSaveIsLoggedAndAFalse()

	/**
	 * A read that answers something other than a list finds nothing, and
	 * find() only returns the row of that portal and recording.
	 *
	 * @return void
	 */
	public function testFindOnlyReturnsTheMatchingRow(): void {
		$this->assertNull($this->store(service: $this->service(rows: 'not a list'))->find(portal: 'zuid', recordingId: 'r1'));

		$rows = [['portal' => 'noord', 'recordingId' => 'r1'], ['portal' => 'zuid', 'recordingId' => 'r2'], ['portal' => 'zuid', 'recordingId' => 'r1', 'n' => 3]];
		$this->asked = [];
		$found = $this->store(service: $this->service(rows: $rows))->find(portal: 'zuid', recordingId: 'r1');

		$this->assertSame(['portal' => 'zuid', 'recordingId' => 'r1', 'n' => 3], $found);
		$this->assertSame([['findAll' => [['filters' => ['portal' => 'zuid', 'recordingId' => 'r1'], 'limit' => 5, 'offset' => 0], false, false]]], $this->asked);
		$this->assertNull($this->store(service: $this->service(rows: $rows))->find(portal: 'zuid', recordingId: 'r9'));
	}//end testFindOnlyReturnsTheMatchingRow()

	/**
	 * The store under test.
	 *
	 * @param object|null $service        The object service, or null for none.
	 * @param bool        $contextApplies Whether the context points the service at the schema.
	 *
	 * @return TrafficRecordingStore
	 */
	private function store(?object $service, bool $contextApplies = true): TrafficRecordingStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($service): object {
			if ($id === self::OS && $service !== null) {
				return $service;
			}

			throw new RuntimeException('no ' . $id);
		});

		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn($contextApplies);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});

		return new TrafficRecordingStore(container: $container, logger: $logger, context: $context);
	}//end store()

	/**
	 * An object service that records what it was asked.
	 *
	 * @param \Throwable|null $saveFails What a save throws.
	 * @param mixed           $rows      What a read answers.
	 *
	 * @return object
	 */
	private function service(?\Throwable $saveFails = null, mixed $rows = []): object {
		$asked = &$this->asked;

		return new class($asked, $saveFails, $rows) {
			/**
			 * @param array<int, mixed> $asked     What the store was asked.
			 * @param \Throwable|null   $saveFails What a save throws.
			 * @param mixed             $rows      What a read answers.
			 */
			public function __construct(private array &$asked, private ?\Throwable $saveFails, private mixed $rows) {
			}

			/**
			 * @param array<string, mixed> $object        The object.
			 * @param mixed                $register      The register.
			 * @param mixed                $schema        The schema.
			 * @param string|null          $uuid          The uuid.
			 * @param bool                 $_rbac         Access rules.
			 * @param bool                 $_multitenancy Tenancy rules.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->asked[] = ['saveObject' => [$object, $register, $schema, $uuid, $_rbac, $_multitenancy]];
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				return $object;
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

				return $this->rows;
			}
		};
	}//end service()
}//end class
