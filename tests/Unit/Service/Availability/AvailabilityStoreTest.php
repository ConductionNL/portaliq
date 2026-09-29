<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Availability;

use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * operate-availability-report: the availability records go through
 * OpenRegister's REAL ObjectService methods (a double built with
 * onlyMethods() on the real class refuses a method it lacks), RBAC off.
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-thirteen-months-are-kept-and-no-more-req-oar-003
 */
class AvailabilityStoreTest extends TestCase {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	public function testAnOldRecordIsRemovedAndAKeptOneIsNot(): void {
		$service = $this->objectService();
		$service->method('findAll')->willReturnCallback(
			static fn (array $config): array => (isset($config['filters']['date']) === true
				? [['uuid' => 'd-old', 'portal' => 'p', 'date' => '2025-07-01'], ['uuid' => 'd-kept', 'portal' => 'p', 'date' => '2025-09-01']]
				: [['@self' => ['uuid' => 'o-old'], 'portal' => 'p', 'startedAt' => '2025-07-01T10:00:00+00:00']])
		);
		$deleted = [];
		$service->method('deleteObject')->willReturnCallback(
			static function (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true) use (&$deleted): bool {
				$deleted[] = [$uuid, $schema, $_rbac];
				return true;
			}
		);

		$removed = $this->store($service)->purgeBefore(cutoff: '2025-08-29');

		$this->assertSame(2, $removed);
		$this->assertSame([['d-old', 'portalAvailabilityDaily', false], ['o-old', 'portalAvailabilityOutage', false]], $deleted);
	}//end testAnOldRecordIsRemovedAndAKeptOneIsNot()

	public function testASavedRecordUpdatesInPlaceWithoutItsEnvelope(): void {
		$service = $this->objectService();
		$saved = [];
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true) use (&$saved): object {
				$saved[] = [$object, $schema, $uuid, $_rbac];
				return $this->getMockBuilder('OCA\\OpenRegister\\Db\\ObjectEntity')->disableOriginalConstructor()->getMock();
			}
		);

		$store = $this->store($service);
		$this->assertTrue($store->save(schema: AvailabilityStore::DAILY_SCHEMA, row: ['@self' => ['uuid' => 'd-1'], 'portal' => 'p', 'date' => '2026-09-29', 'intervals' => 3]));
		$this->assertTrue($store->save(schema: AvailabilityStore::OUTAGE_SCHEMA, row: ['portal' => 'p', 'startedAt' => '2026-09-29T10:00:00+00:00', 'cause' => 'timeout']));

		$this->assertSame([['portal' => 'p', 'date' => '2026-09-29', 'intervals' => 3], 'portalAvailabilityDaily', 'd-1', false], $saved[0]);
		$this->assertNull($saved[1][2]);
	}//end testASavedRecordUpdatesInPlaceWithoutItsEnvelope()

	public function testTheOpenOutageIsTheOneWithoutAnEnd(): void {
		$service = $this->objectService();
		$service->method('findAll')->willReturn([
			['uuid' => 'o-1', 'portal' => 'p', 'startedAt' => '2026-09-28T10:00:00+00:00', 'endedAt' => '2026-09-28T10:10:00+00:00'],
			['uuid' => 'o-2', 'portal' => 'p', 'startedAt' => '2026-09-29T10:00:00+00:00'],
		]);

		$this->assertSame('o-2', $this->store($service)->openOutage(portal: 'p')['uuid']);
	}//end testTheOpenOutageIsTheOneWithoutAnEnd()

	/**
	 * A double of OpenRegister's real ObjectService.
	 *
	 * @return MockObject
	 */
	private function objectService(): MockObject {
		if (class_exists(self::OBJECT_SERVICE) === false) {
			$this->markTestSkipped('OpenRegister ObjectService not loadable: set PORTALIQ_OPENREGISTER_LIB');
		}

		return $this->getMockBuilder(self::OBJECT_SERVICE)
			->disableOriginalConstructor()
			->onlyMethods(['findAll', 'saveObject', 'deleteObject'])
			->getMock();
	}//end objectService()

	/**
	 * The store over the double.
	 *
	 * @param object $service The ObjectService double.
	 *
	 * @return AvailabilityStore
	 */
	private function store(object $service): AvailabilityStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return new AvailabilityStore($container, $this->createMock(LoggerInterface::class), $context);
	}//end store()
}//end class
