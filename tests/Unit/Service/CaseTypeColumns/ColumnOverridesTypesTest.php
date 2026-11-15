<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\CaseTypeColumns;

use OCA\Portaliq\Service\CaseTypeColumns\ColumnOverrides;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\PortalResolver;
use PHPUnit\Framework\TestCase;

/**
 * Which case type a row's columns are taken from, and when rows are
 * passed through untouched.
 *
 * @covers \OCA\Portaliq\Service\CaseTypeColumns\ColumnOverrides
 * @uses   \OCA\Portaliq\Service\CaseTypeVisibility
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
 */
class ColumnOverridesTypesTest extends TestCase {
	private const STATUS = ['field' => 'status', 'render' => 'text'];

	private const LINK = ['field' => 'website', 'render' => 'link', 'linkLabel' => 'Open'];

	public function testTheColumnsAreKeyedByFieldInTheirStoredOrder(): void {
		$row = $this->overrides()->row(row: ['caseType' => 'parkeren'], collection: ['kind' => 'cases']);

		$this->assertSame(['status', 'website'], array_keys($row['_columns']));
		$this->assertSame(['status' => self::STATUS, 'website' => self::LINK], $row['_columns']);
	}//end testTheColumnsAreKeyedByFieldInTheirStoredOrder()

	public function testTheCollectionsOwnTypeFieldIsRead(): void {
		$row = $this->overrides()->row(
			row: ['caseType' => 'afval', 'zaaktype' => 'parkeren'],
			collection: ['kind' => 'cases', 'caseTypeField' => 'zaaktype']
		);

		$this->assertSame(['status' => self::STATUS, 'website' => self::LINK], $row['_columns']);
	}//end testTheCollectionsOwnTypeFieldIsRead()

	public function testATypeGivenAsAnObjectIsReadByItsId(): void {
		$rows = $this->overrides()->rows(
			rows: [['caseType' => ['id' => 'parkeren', 'title' => 'Parkeren']], ['caseType' => ['uuid' => 'parkeren']], ['caseType' => ['title' => 'Geen id']]],
			collection: ['kind' => 'cases']
		);

		$this->assertArrayHasKey('_columns', $rows[0]);
		$this->assertArrayHasKey('_columns', $rows[1]);
		$this->assertArrayNotHasKey('_columns', $rows[2]);
	}//end testATypeGivenAsAnObjectIsReadByItsId()

	public function testARowWithoutATypeStaysAsItIs(): void {
		$row = ['title' => 'Geen type'];

		$this->assertSame($row, $this->overrides()->row(row: $row, collection: ['kind' => 'cases']));
	}//end testARowWithoutATypeStaysAsItIs()

	public function testNoSettingsOrAnotherKindPassesTheRowsThroughUntouched(): void {
		$rows = [['caseType' => 'parkeren'], 'kapot', 7];
		$types = new CaseTypeVisibility($this->createMock(PortalResolver::class));

		$this->assertSame($rows, (new ColumnOverrides(byType: [], types: $types))->rows(rows: $rows, collection: ['kind' => 'cases']));
		$this->assertSame($rows, $this->overrides()->rows(rows: $rows, collection: []));
	}//end testNoSettingsOrAnotherKindPassesTheRowsThroughUntouched()

	public function testWithoutATypeReaderTheRowsAreReturnedUnstamped(): void {
		$rows = [['caseType' => 'parkeren'], 'kapot'];

		$this->assertSame(
			$rows,
			(new ColumnOverrides(byType: ['parkeren' => [self::STATUS]], types: null))->rows(rows: $rows, collection: ['kind' => 'cases'])
		);
	}//end testWithoutATypeReaderTheRowsAreReturnedUnstamped()

	/**
	 * Overrides with two columns on the type `parkeren`.
	 *
	 * @return ColumnOverrides
	 */
	private function overrides(): ColumnOverrides {
		return new ColumnOverrides(
			byType: ['parkeren' => [self::STATUS, self::LINK]],
			types: new CaseTypeVisibility($this->createMock(PortalResolver::class))
		);
	}//end overrides()
}//end class
