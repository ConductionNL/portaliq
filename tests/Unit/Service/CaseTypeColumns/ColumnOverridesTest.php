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
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A case row carries the serving portal's column settings for its case type.
 *
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
 */
class ColumnOverridesTest extends TestCase {
	private const QR = ['field' => 'bewijs', 'render' => 'qr', 'linkLabel' => 'Toevoegen aan wallet'];

	public function testACaseRowOfASetTypeCarriesItsColumns(): void {
		$rows = $this->overrides()->rows(
			rows: [['caseType' => 'parkeren', 'bewijs' => 'https://x.nl/b'], ['caseType' => 'afval'], 'kapot'],
			collection: ['kind' => 'cases']
		);

		$this->assertSame(['bewijs' => self::QR], $rows[0]['_columns']);
		$this->assertArrayNotHasKey('_columns', $rows[1]);
		$this->assertSame('kapot', $rows[2]);
	}//end testACaseRowOfASetTypeCarriesItsColumns()

	public function testARowOfAnotherKindIsLeftAlone(): void {
		$row = ['caseType' => 'parkeren'];

		$this->assertSame($row, $this->overrides()->row(row: $row, collection: ['kind' => 'documents']));
		$this->assertSame([$row], $this->overrides()->rows(rows: [$row], collection: ['kind' => 'documents']));
		$this->assertSame($row, (new ColumnOverrides(byType: [], types: null))->row(row: $row, collection: ['kind' => 'cases']));
	}//end testARowOfAnotherKindIsLeftAlone()

	public function testTheServingPortalsSettingsApply(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'mijn', 'caseTypeColumns' => ['parkeren' => [self::QR]]]);
		$overrides = (new CaseTypeVisibility($portals))->servingColumns(request: $this->createMock(IRequest::class), subject: []);

		$row = $overrides->row(row: ['caseType' => 'parkeren'], collection: ['kind' => 'cases']);

		$this->assertSame(['bewijs' => self::QR], $row['_columns']);
	}//end testTheServingPortalsSettingsApply()

	/**
	 * Overrides that set one QR column on the type `parkeren`.
	 *
	 * @return ColumnOverrides
	 */
	private function overrides(): ColumnOverrides {
		return new ColumnOverrides(
			byType: ['parkeren' => [self::QR]],
			types: new CaseTypeVisibility($this->createMock(PortalResolver::class))
		);
	}//end overrides()
}//end class
