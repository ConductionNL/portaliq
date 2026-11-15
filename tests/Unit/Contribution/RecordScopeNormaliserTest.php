<?php

/**
 * Unit tests for RecordScopeNormaliser.
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\RecordScopeNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The record scope and lookups a block declares, kept only where they are names.
 */
#[CoversClass(RecordScopeNormaliser::class)]
class RecordScopeNormaliserTest extends TestCase {

	/**
	 * The normaliser under test.
	 *
	 * @var RecordScopeNormaliser
	 */
	private RecordScopeNormaliser $scopes;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->scopes = new RecordScopeNormaliser();
	}//end setUp()


	/**
	 * The record field carries its key; a key without a field is dropped.
	 *
	 * @return void
	 */
	public function testScopeKeepsTheRecordFieldAndItsKey(): void {
		$this->assertSame(
			['type' => 'tasks', 'recordField' => 'pupil', 'recordKey' => 'uuid', 'recordGroupsField' => 'groups'],
			$this->scopes->scope(['recordField' => 'pupil', 'recordKey' => 'uuid', 'recordGroupsField' => 'groups'], ['type' => 'tasks'])
		);
		$this->assertSame(['type' => 'tasks'], $this->scopes->scope(['recordKey' => 'uuid', 'recordField' => ''], ['type' => 'tasks']));
		$this->assertSame(['recordField' => 'pupil'], $this->scopes->scope(['recordField' => 'pupil', 'recordKey' => 5], []));
	}//end testScopeKeepsTheRecordFieldAndItsKey()


	/**
	 * A lookup needs a known collection and three names; its optional keys are kept when sound.
	 *
	 * @return void
	 */
	public function testLookupsKeepOnlySoundEntries(): void {
		$entry = $this->scopes->lookups(
			[
				'lookups' => [
					[
						'as'          => 'teacher',
						'collection'  => 'staff',
						'matchField'  => 'id',
						'valueField'  => 'name',
						'recordField' => 'pupil',
						'rowField'    => '',
						'values'      => ['a' => 'Alpha', 'b' => '', 0 => 'Zero'],
						'fallback'    => 'Unknown',
						'other'       => 'dropped',
					],
					['as' => 'x', 'collection' => 'unknown', 'matchField' => 'id', 'valueField' => 'name'],
					['as' => 'y', 'collection' => 'staff', 'matchField' => 'id'],
					'not a lookup',
				],
			],
			['type' => 'tasks'],
			['staff']
		);

		$this->assertSame(
			[
				'type'    => 'tasks',
				'lookups' => [
					[
						'as'          => 'teacher',
						'collection'  => 'staff',
						'matchField'  => 'id',
						'valueField'  => 'name',
						'recordField' => 'pupil',
						'values'      => ['a' => 'Alpha'],
						'fallback'    => 'Unknown',
					],
				],
			],
			$entry
		);
	}//end testLookupsKeepOnlySoundEntries()


	/**
	 * Without a sound lookup the entry carries no lookups key at all.
	 *
	 * @return void
	 */
	public function testNoSoundLookupLeavesNoKey(): void {
		$this->assertSame(['type' => 'tasks'], $this->scopes->lookups(['lookups' => 'x'], ['type' => 'tasks'], ['staff']));
		$this->assertSame(['type' => 'tasks'], $this->scopes->lookups([], ['type' => 'tasks'], ['staff']));
	}//end testNoSoundLookupLeavesNoKey()


	/**
	 * A collection that lists its fields gains each lookup's alias, once.
	 *
	 * @return void
	 */
	public function testWithLookupFieldsAddsTheAliases(): void {
		$entry = ['lookups' => [['as' => 'teacher'], ['as' => 'title']]];

		$this->assertSame(
			['id' => 'tasks', 'fields' => ['title', 'due', 'teacher']],
			$this->scopes->withLookupFields(['id' => 'tasks', 'fields' => ['title', 'due']], $entry)
		);
		$this->assertSame(['id' => 'tasks'], $this->scopes->withLookupFields(['id' => 'tasks'], $entry));
		$this->assertNull($this->scopes->withLookupFields(null, $entry));
	}//end testWithLookupFieldsAddsTheAliases()
}//end class
