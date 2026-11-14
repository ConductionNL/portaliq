<?php

/**
 * CaseStatusLabelField: the field a case list reads its status label from,
 * and the label stamped on each row.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CaseStatusLabelField;
use PHPUnit\Framework\TestCase;

/**
 * The status label field.
 */
class CaseStatusLabelFieldTest extends TestCase {
	/**
	 * A collection without the key is left as it was.
	 *
	 * @return void
	 */
	public function testACollectionWithoutTheKeyIsUntouched(): void {
		$collection = ['id' => 'cases', 'fields' => ['status']];
		$this->assertSame(expected: $collection, actual: (new CaseStatusLabelField())->normalise(collection: $collection));
	}//end testACollectionWithoutTheKeyIsUntouched()

	/**
	 * The field is kept when it is projected, or when the collection names no field list.
	 *
	 * @return void
	 */
	public function testAProjectedFieldIsKept(): void {
		$field = new CaseStatusLabelField();
		$this->assertSame(
			expected: 'statusText',
			actual: $field->normalise(collection: ['fields' => ['statusText'], 'statusLabelField' => 'statusText'])['statusLabelField']
		);
		$this->assertSame(expected: 'statusText', actual: $field->normalise(collection: ['statusLabelField' => 'statusText'])['statusLabelField']);
	}//end testAProjectedFieldIsKept()

	/**
	 * A field that is not projected, empty, or not a name is removed.
	 *
	 * @return void
	 */
	public function testAnUnusableFieldIsRemoved(): void {
		$field = new CaseStatusLabelField();
		foreach (['secret', '', null, ['statusText'], 5] as $declared) {
			$kept = $field->normalise(collection: ['fields' => ['statusText'], 'statusLabelField' => $declared]);
			$this->assertSame(expected: ['fields' => ['statusText']], actual: $kept);
		}
	}//end testAnUnusableFieldIsRemoved()

	/**
	 * A row is stamped with its status label when it has one.
	 *
	 * @return void
	 */
	public function testARowIsStampedWithItsLabel(): void {
		$field      = new CaseStatusLabelField();
		$collection = ['statusLabelField' => 'statusText'];
		$this->assertSame(
			expected: ['id' => 'c1', 'statusText' => 'In behandeling', '_statusLabel' => 'In behandeling'],
			actual: $field->stamp(row: ['id' => 'c1', 'statusText' => 'In behandeling'], collection: $collection)
		);
		foreach (['   ', null, 3, ['x']] as $value) {
			$row = ['id' => 'c1', 'statusText' => $value];
			$this->assertSame(expected: $row, actual: $field->stamp(row: $row, collection: $collection));
		}

		$this->assertSame(expected: ['id' => 'c1'], actual: $field->stamp(row: ['id' => 'c1'], collection: $collection));
	}//end testARowIsStampedWithItsLabel()

	/**
	 * Without a status label field no row is stamped.
	 *
	 * @return void
	 */
	public function testWithoutAFieldNothingIsStamped(): void {
		$row = ['id' => 'c1', '' => 'x', 'statusText' => 'Open'];
		$this->assertSame(expected: $row, actual: (new CaseStatusLabelField())->stamp(row: $row, collection: []));
	}//end testWithoutAFieldNothingIsStamped()
}//end class
