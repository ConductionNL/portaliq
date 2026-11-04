<?php

/**
 * Golden test for ExampleResidentRemover::remove.
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

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentRecord;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentStore;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentUser;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentWayIn;
use PHPUnit\Framework\TestCase;

/**
 * Every collaborator call and the report of a removal, per world, against a
 * recording made on the code before the split.
 *
 * A world holds the install record, whether the store is available, which
 * object ids are already gone and which deletes fail, and how the portal
 * account, the sign-in mode and the Nextcloud account answer.
 *
 * @covers \OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover
 */
class ExampleResidentRemoverGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/example-resident-remover.json';

	/**
	 * @return void
	 */
	public function testEveryWorldMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $world) {
			$actual[$name] = $this->trace(world: $world);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryWorldMatchesTheGolden()

	/**
	 * Remove in one world; the calls in order, then the report.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return array<string, mixed> `calls` and `report`.
	 */
	private function trace(array $world): array {
		$log    = [];
		$record = $this->createMock(originalClassName: ExampleResidentRecord::class);
		$record->method('read')->willReturnCallback(
			static function (string $id) use (&$log, $world): array {
				$log[] = ['record.read', $id];
				return $world['record'];
			}
		);
		$record->method('isEmpty')->willReturnCallback(
			static function (array $value) use (&$log): bool {
				$log[] = ['record.isEmpty', $value];
				return $value['userId'] === '' && $value['account'] === '' && $value['signIn']['mode'] === '' && $value['objects'] === [];
			}
		);
		$record->method('write')->willReturnCallback(
			static function (string $id, array $value) use (&$log): void {
				$log[] = ['record.write', $id, $value];
			}
		);

		$store = $this->createMock(originalClassName: ExampleResidentStore::class);
		$store->method('available')->willReturnCallback(
			static function () use (&$log, $world): bool {
				$log[] = ['store.available'];
				return ($world['available'] ?? true);
			}
		);
		$store->method('get')->willReturnCallback(
			static function (string $register, string $schema, string $id) use (&$log, $world): ?array {
				$log[] = ['store.get', $register, $schema, $id];
				if (in_array($id, ($world['gone'] ?? []), true) === true) {
					return null;
				}

				return ['id' => $id];
			}
		);
		$store->method('delete')->willReturnCallback(
			static function (string $register, string $schema, string $id) use (&$log, $world): bool {
				$log[] = ['store.delete', $register, $schema, $id];
				return in_array($id, ($world['undeletable'] ?? []), true) === false;
			}
		);

		$wayIn = $this->createMock(originalClassName: ExampleResidentWayIn::class);
		$wayIn->method('account')->willReturnCallback(
			static function (string $id) use (&$log, $world): ?array {
				$log[] = ['wayIn.account', $id];
				if (array_key_exists('account', $world) === true) {
					return $world['account'];
				}

				return ['id' => $id];
			}
		);
		$wayIn->method('deleteAccount')->willReturnCallback(
			static function (string $id) use (&$log, $world): bool {
				$log[] = ['wayIn.deleteAccount', $id];
				return ($world['accountDeletes'] ?? true);
			}
		);
		$wayIn->method('portal')->willReturnCallback(
			static function (string $slug) use (&$log, $world): ?array {
				$log[] = ['wayIn.portal', $slug];
				if (array_key_exists('portal', $world) === true) {
					return $world['portal'];
				}

				return ['slug' => $slug];
			}
		);
		$wayIn->method('withdraw')->willReturnCallback(
			static function (array $portal, string $mode, bool $label) use (&$log, $world): bool {
				$log[] = ['wayIn.withdraw', $portal, $mode, $label];
				return ($world['withdraws'] ?? true);
			}
		);

		$user = $this->createMock(originalClassName: ExampleResidentUser::class);
		$user->method('delete')->willReturnCallback(
			static function (string $userId) use (&$log, $world): bool {
				$log[] = ['user.delete', $userId];
				return ($world['userDeletes'] ?? true);
			}
		);

		$remover = new ExampleResidentRemover(store: $store, user: $user, wayIn: $wayIn, record: $record);
		$report  = $remover->remove(id: 'sanne', slug: 'zuiddrecht');

		return ['calls' => $log, 'report' => $report];
	}//end trace()

	/**
	 * An install record.
	 *
	 * @param array<string, mixed> $changes Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $changes = []): array {
		return array_replace(
			[
				'userId'      => 'sanne',
				'userCreated' => true,
				'account'     => 'acc-1',
				'signIn'      => ['mode' => 'password', 'label' => true],
				'objects'     => [
					'case'    => ['register' => 'procest', 'schema' => 'case', 'id' => 'c-1', 'subject' => 'sanne'],
					'message' => ['register' => 'portaliq', 'schema' => 'message', 'id' => 'm-1', 'subject' => 'sanne'],
					'7'       => ['register' => 'portaliq', 'schema' => 'message', 'id' => 'm-2', 'subject' => 'sanne'],
					'draft'   => ['register' => 'portaliq', 'schema' => 'draft', 'id' => 'd-1', 'subject' => 'sanne'],
				],
			],
			$changes
		);
	}//end record()

	/**
	 * The worlds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$blank = ['userId' => '', 'userCreated' => false, 'account' => '', 'signIn' => ['mode' => '', 'label' => false], 'objects' => []];

		return [
			'no record'                         => ['record' => $blank],
			'no record, store down'             => ['record' => $blank, 'available' => false],
			'store down'                        => ['record' => $this->record(), 'available' => false],
			'everything removed'                => ['record' => $this->record()],
			'some objects gone'                 => ['record' => $this->record(), 'gone' => ['m-1', 'd-1']],
			'some deletes fail'                 => ['record' => $this->record(), 'undeletable' => ['c-1', 'm-2']],
			'gone and failing mixed'            => ['record' => $this->record(), 'gone' => ['c-1'], 'undeletable' => ['m-1']],
			'only a user id'                    => ['record' => array_replace($blank, ['userId' => 'sanne'])],
			'user not created'                  => ['record' => $this->record(changes: ['userCreated' => false])],
			'user created without id'           => ['record' => $this->record(changes: ['userId' => ''])],
			'user delete fails'                 => ['record' => $this->record(), 'userDeletes' => false],
			'account already gone'              => ['record' => $this->record(), 'account' => null, 'accountDeletes' => false],
			'account delete fails'              => ['record' => $this->record(), 'accountDeletes' => false],
			'no account'                        => ['record' => $this->record(changes: ['account' => ''])],
			'no sign-in mode'                   => ['record' => $this->record(changes: ['signIn' => ['mode' => '', 'label' => true]])],
			'portal gone'                       => ['record' => $this->record(), 'portal' => null, 'withdraws' => false],
			'withdraw fails'                    => ['record' => $this->record(), 'withdraws' => false],
			'withdraw without label'            => ['record' => $this->record(changes: ['signIn' => ['mode' => 'digid', 'label' => false]])],
			'everything fails'                  => [
				'record' => $this->record(),
				'undeletable' => ['c-1', 'm-1', 'm-2', 'd-1'],
				'accountDeletes' => false,
				'withdraws' => false,
				'userDeletes' => false,
			],
			'objects only'                      => ['record' => array_replace($blank, ['objects' => $this->record()['objects']])],
			'one object type twice, one failing' => [
				'record' => array_replace(
						$blank,
						[
							'objects' => [
								'a' => ['register' => 'r', 'schema' => 's', 'id' => 'x-1', 'subject' => ''],
								'b' => ['register' => 'r', 'schema' => 's', 'id' => 'x-2', 'subject' => ''],
							],
						]
					),
				'undeletable' => ['x-1'],
			],
		];
	}//end worlds()
}//end class
