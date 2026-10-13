<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentStore;
use OCA\Portaliq\Service\ExampleResident\ExampleSetResidents;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Who the example resident of a portal is: only the portal account the
 * example set recorded, and only when its account id is one the set made
 * or adopted.
 *
 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
 */
class ExampleSetResidentsTest extends TestCase {
	/**
	 * The install record OpenRegister keeps for set `zuiddrecht`.
	 *
	 * @var array<string, mixed>
	 */
	private array $record = [];

	/**
	 * The portal accounts on the instance, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $accounts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->record   = [
			'users'   => ['sanne.devries' => 'created'],
			'objects' => ['portaliq/portaalaccount-sanne' => ['register' => 'portaliq', 'schema' => 'portalAccount', 'id' => 'acc-1']],
			'changes' => ['portaliq/inloggen-voorbeeldinwoner' => ['register' => 'portaliq', 'schema' => 'portal', 'id' => 'p-1']],
		];
		$this->accounts = ['acc-1' => ['id' => 'acc-1', 'subjectRef' => 'sanne.devries']];
	}

	/**
	 * The service over the shipped fragment folder, with or without OpenRegister.
	 *
	 * @param bool $withOpenRegister Whether OpenRegister's record can be fetched.
	 *
	 * @return ExampleSetResidents
	 */
	private function residents(bool $withOpenRegister = true): ExampleSetResidents {
		$records = new class($this) {
			public function __construct(private readonly ExampleSetResidentsTest $test) {
			}

			public function read(string $set): array {
				return ($set === 'zuiddrecht') ? $this->test->recordOf() : [];
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($withOpenRegister, $records): object {
				if ($withOpenRegister === false) {
					throw new RuntimeException('no such service');
				}

				return $records;
			}
		);
		$store = $this->createMock(originalClassName: ExampleResidentStore::class);
		$store->method('get')->willReturnCallback(fn (string $register, string $schema, string $id): ?array => ($this->accounts[$id] ?? null));

		return new ExampleSetResidents(container: $container, store: $store);
	}//end residents()

	/**
	 * The current record, for the stand-in of OpenRegister's record service.
	 *
	 * @return array<string, mixed>
	 */
	public function recordOf(): array {
		return $this->record;
	}//end recordOf()

	public function testTheInstalledResidentOfThePortal(): void {
		$this->assertSame(
			['set' => 'zuiddrecht', 'uid' => 'sanne.devries', 'mode' => 'nextcloud', 'modeAdded' => true],
			$this->residents()->forPortal(portal: 'zuiddrecht')
		);
		$this->assertNull($this->residents()->forPortal(portal: 'elders'));
	}

	public function testAModeThePortalAlreadyOfferedIsNotTheSets(): void {
		unset($this->record['changes']['portaliq/inloggen-voorbeeldinwoner']);

		$this->assertFalse($this->residents()->forPortal(portal: 'zuiddrecht')['modeAdded']);
	}

	public function testAnAccountEditedToSomebodyElseMakesNobodyTheResident(): void {
		$this->accounts['acc-1']['subjectRef'] = 'admin';

		$this->assertNull($this->residents()->forPortal(portal: 'zuiddrecht'));
	}

	public function testAPortalAccountTheSetDidNotRecordMakesNobodyTheResident(): void {
		$this->record['objects'] = [];

		$this->assertNull($this->residents()->forPortal(portal: 'zuiddrecht'));
	}

	public function testWithoutOpenRegisterThereIsNoResident(): void {
		$this->assertFalse($this->residents(withOpenRegister: false)->available());
		$this->assertNull($this->residents(withOpenRegister: false)->forPortal(portal: 'zuiddrecht'));
		$this->assertTrue($this->residents()->available());
	}
}
