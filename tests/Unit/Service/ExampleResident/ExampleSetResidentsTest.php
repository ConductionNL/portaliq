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
			'users'   => ['sanne.devries' => 'created', 'henk.bakker' => 'created', 'linda.bakker' => 'adopted'],
			'objects' => [
				'portaliq/portaalaccount-sanne' => ['register' => 'portaliq', 'schema' => 'portalAccount', 'id' => 'acc-1'],
				'portaliq/portaalaccount-henk'  => ['register' => 'portaliq', 'schema' => 'portalAccount', 'id' => 'acc-2'],
				'portaliq/portaalaccount-linda' => ['register' => 'portaliq', 'schema' => 'portalAccount', 'id' => 'acc-3'],
			],
		];
		$this->accounts = [
			'acc-1' => ['id' => 'acc-1', 'subjectRef' => 'sanne.devries', 'displayName' => 'Sanne de Vries'],
			'acc-2' => ['id' => 'acc-2', 'subjectRef' => 'henk.bakker', 'displayName' => 'Henk Bakker'],
			'acc-3' => ['id' => 'acc-3', 'subjectRef' => 'linda.bakker', 'displayName' => ''],
		];
	}

	/**
	 * The service over the shipped fragment folder, with or without OpenRegister.
	 *
	 * @param bool $withOpenRegister Whether OpenRegister's record can be fetched.
	 *
	 * @return ExampleSetResidents
	 */
	private function residents(bool $withOpenRegister = true): ExampleSetResidents {
		$records = null;
		if ($withOpenRegister === true) {
			$records = $this->recordStandIn();
		}

		return new ExampleSetResidents(container: $this->containerWith(records: $records), store: $this->storeStandIn());
	}//end residents()

	/**
	 * A stand-in for OpenRegister's record service, reading this test's record.
	 *
	 * @return object
	 */
	private function recordStandIn(): object {
		return new class($this) {
			public function __construct(private readonly ExampleSetResidentsTest $test) {
			}

			public function read(string $set): array {
				return ($set === 'zuiddrecht') ? $this->test->recordOf() : [];
			}
		};
	}//end recordStandIn()

	/**
	 * A container handing out the record service, or failing like an instance without OpenRegister.
	 *
	 * @param object|null $records The record service, or null.
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(?object $records): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($records): object {
				if ($records === null) {
					throw new RuntimeException('no such service');
				}

				return $records;
			}
		);

		return $container;
	}//end containerWith()

	/**
	 * The portal accounts of this test, by id.
	 *
	 * @return ExampleResidentStore
	 */
	private function storeStandIn(): ExampleResidentStore {
		$store = $this->createMock(originalClassName: ExampleResidentStore::class);
		$store->method('get')->willReturnCallback(fn (string $register, string $schema, string $id): ?array => ($this->accounts[$id] ?? null));

		return $store;
	}//end storeStandIn()

	/**
	 * The current record, for the stand-in of OpenRegister's record service.
	 *
	 * @return array<string, mixed>
	 */
	public function recordOf(): array {
		return $this->record;
	}//end recordOf()

	/**
	 * Each resident the record vouches for, in the fragment's order; a resident
	 * whose account the set did not record (Fatima here) is left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function testTheInstalledResidentsOfThePortal(): void {
		$residents = $this->residents()->residentsOf(portal: 'zuiddrecht');

		$this->assertSame(['sanne', 'henk', 'linda'], array_column($residents, 'key'));
		$this->assertSame(['sanne.devries', 'henk.bakker', 'linda.bakker'], array_column($residents, 'uid'));
		$this->assertSame('zuiddrecht', $residents[0]['set']);
		$this->assertSame('Sanne de Vries', $residents[0]['name']);
		// A portal account without a name shows its account id.
		$this->assertSame('linda.bakker', $residents[2]['name']);
		$this->assertSame('Linda Bakker', $residents[2]['card']['title']);
		$this->assertSame([], $this->residents()->residentsOf(portal: 'elders'));
	}

	/**
	 * @return void
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function testAnAccountEditedToSomebodyElseDropsOnlyThatResident(): void {
		$this->accounts['acc-2']['subjectRef'] = 'admin';

		$this->assertSame(['sanne', 'linda'], array_column($this->residents()->residentsOf(portal: 'zuiddrecht'), 'key'));
	}

	/**
	 * @return void
	 */
	public function testAPortalAccountTheSetDidNotRecordMakesNobodyTheResident(): void {
		$this->record['objects'] = [];

		$this->assertSame([], $this->residents()->residentsOf(portal: 'zuiddrecht'));
	}

	/**
	 * A fragment that names one resident as an object still counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function testOneResidentWrittenAsAnObject(): void {
		$folder = sys_get_temp_dir().'/portaliq-example-sets-'.uniqid();
		mkdir($folder);
		file_put_contents(
			$folder.'/zuiddrecht.json',
			(string)json_encode(['exampleResident' => ['portal' => 'zuiddrecht', 'cast' => 'sanne', 'account' => 'portaalaccount-sanne']])
		);

		$residents = new ExampleSetResidents(
			container: $this->containerWith(records: $this->recordStandIn()),
			store: $this->storeStandIn(),
			folder: $folder
		);

		$this->assertSame(['sanne'], array_column($residents->residentsOf(portal: 'zuiddrecht'), 'key'));
		$this->assertSame([], $residents->residentsOf(portal: 'zuiddrecht')[0]['card']);
		unlink($folder.'/zuiddrecht.json');
		rmdir($folder);
	}

	/**
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNoResident(): void {
		$this->assertFalse($this->residents(withOpenRegister: false)->available());
		$this->assertSame([], $this->residents(withOpenRegister: false)->residentsOf(portal: 'zuiddrecht'));
		$this->assertTrue($this->residents()->available());
	}
}
