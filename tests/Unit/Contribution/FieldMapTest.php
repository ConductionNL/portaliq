<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A create action's answers land where its `fieldMap` says, and a stored
 * draft reads back under the answers' own names (create-action-field-map).
 *
 * @spec openspec/changes/create-action-field-map/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-say-where-each-answer-lands
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\FieldMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldMap::class)]
class FieldMapTest extends TestCase {

	private const ACTION = [
		'type' => 'create',
		'fieldMap' => [
			'onderwerp' => 'title',
			'periodeVan' => 'wooRequest.periodeVan',
			'verzoekerNaam' => 'wooRequest.verzoekerNaam',
			'bad' => 'not a path',
			'1x' => 'title',
		],
	];

	/**
	 * Mapped answers move to their paths, the rest stays.
	 *
	 * @return void
	 */
	public function testAnswersMoveToTheirPaths(): void {
		$body = (new FieldMap())->apply(
			action: self::ACTION,
			body: ['onderwerp' => 'Verlichting', 'periodeVan' => '2025-01-01', 'caseType' => 'ct-1', 'bad' => 'kept']
		);

		$this->assertSame(
			expected: ['caseType' => 'ct-1', 'bad' => 'kept', 'title' => 'Verlichting', 'wooRequest' => ['periodeVan' => '2025-01-01']],
			actual: $body
		);
	}//end testAnswersMoveToTheirPaths()

	/**
	 * A stored object reads back under the answers' names.
	 *
	 * @return void
	 */
	public function testAStoredObjectReadsBackUnderTheAnswerNames(): void {
		$data = (new FieldMap())->reverse(
			action: self::ACTION,
			data: ['title' => 'Verlichting', 'wooRequest' => ['periodeVan' => '2025-01-01']]
		);

		$this->assertSame(expected: 'Verlichting', actual: $data['onderwerp']);
		$this->assertSame(expected: '2025-01-01', actual: $data['periodeVan']);
		$this->assertArrayNotHasKey(key: 'verzoekerNaam', array: $data, message: 'nothing at the path, no answer');
	}//end testAStoredObjectReadsBackUnderTheAnswerNames()

	/**
	 * Without a map nothing moves.
	 *
	 * @return void
	 */
	public function testWithoutAMapNothingMoves(): void {
		$body = ['title' => 'T', 'x' => 1];
		$this->assertSame(expected: $body, actual: (new FieldMap())->apply(action: ['type' => 'create'], body: $body));
		$this->assertSame(expected: $body, actual: (new FieldMap())->reverse(action: ['type' => 'create'], data: $body));
	}//end testWithoutAMapNothingMoves()
}//end class
