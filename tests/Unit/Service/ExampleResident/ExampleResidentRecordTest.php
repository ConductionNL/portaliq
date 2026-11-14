<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentRecord;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the example resident left behind, kept per portal in app config:
 * read defensively, written while it holds anything, deleted when empty.
 */
#[CoversClass(ExampleResidentRecord::class)]
class ExampleResidentRecordTest extends TestCase {

	private const BLANK = [
		'userId' => '',
		'userCreated' => false,
		'account' => '',
		'signIn' => ['mode' => '', 'label' => false],
		'objects' => [],
	];

	/**
	 * A record over an app config holding $stored for the key.
	 *
	 * @param string $stored The stored JSON.
	 *
	 * @return ExampleResidentRecord
	 */
	private function record(string $stored): ExampleResidentRecord {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')->with('portaliq', 'example_resident_gem', '')->willReturn($stored);
		return new ExampleResidentRecord($config);
	}//end record()

	public function testNothingStoredReadsAsTheBlankRecord(): void {
		$this->assertSame(self::BLANK, $this->record('')->read('gem'));
		$this->assertSame(self::BLANK, $this->record('"text"')->read('gem'));
	}//end testNothingStoredReadsAsTheBlankRecord()

	public function testAStoredRecordIsReadWithStrictTypes(): void {
		$stored = json_encode([
			'userId' => 'resident',
			'userCreated' => 'yes',
			'account' => 7,
			'signIn' => ['mode' => 'password', 'label' => true],
			'objects' => [
				'case' => ['register' => 'zaken', 'schema' => 'case', 'id' => 'c-1', 'subject' => 'bsn:999'],
				'noId' => ['register' => 'zaken'],
				'emptyId' => ['id' => ''],
				'intId' => ['id' => 5],
				'notAnObject' => 'x',
				0 => ['id' => 'o-2'],
			],
		]);

		$this->assertSame(
			[
				'userId' => 'resident',
				'userCreated' => false,
				'account' => '7',
				'signIn' => ['mode' => 'password', 'label' => true],
				'objects' => [
					'case' => ['register' => 'zaken', 'schema' => 'case', 'id' => 'c-1', 'subject' => 'bsn:999'],
					'0' => ['register' => '', 'schema' => '', 'id' => 'o-2', 'subject' => ''],
				],
			],
			$this->record((string)$stored)->read('gem')
		);
	}//end testAStoredRecordIsReadWithStrictTypes()

	public function testOnlyTheTrueFlagCountsAsCreated(): void {
		$this->assertTrue($this->record('{"userCreated":true}')->read('gem')['userCreated']);
	}//end testOnlyTheTrueFlagCountsAsCreated()

	public function testARecordIsEmptyOnlyWhenItHoldsNothing(): void {
		$record = new ExampleResidentRecord($this->createMock(IAppConfig::class));

		$this->assertTrue($record->isEmpty(self::BLANK));
		$this->assertTrue($record->isEmpty(['userCreated' => true, 'signIn' => ['mode' => '', 'label' => true]] + self::BLANK));
		$this->assertFalse($record->isEmpty(['userId' => 'r'] + self::BLANK));
		$this->assertFalse($record->isEmpty(['account' => 'a'] + self::BLANK));
		$this->assertFalse($record->isEmpty(['signIn' => ['mode' => 'password', 'label' => false]] + self::BLANK));
		$this->assertFalse($record->isEmpty(['objects' => ['x' => []]] + self::BLANK));
	}//end testARecordIsEmptyOnlyWhenItHoldsNothing()

	public function testARecordWithContentIsStoredAsJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('deleteKey');
		$config->expects($this->once())->method('setValueString')
			->with('portaliq', 'example_resident_gem', json_encode(['userId' => 'r'] + self::BLANK));

		(new ExampleResidentRecord($config))->write('gem', ['userId' => 'r'] + self::BLANK);
	}//end testARecordWithContentIsStoredAsJson()

	public function testAnEmptyRecordDeletesTheKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$config->expects($this->once())->method('deleteKey')->with('portaliq', 'example_resident_gem');

		(new ExampleResidentRecord($config))->write('gem', self::BLANK);
	}//end testAnEmptyRecordDeletesTheKey()
}//end class
