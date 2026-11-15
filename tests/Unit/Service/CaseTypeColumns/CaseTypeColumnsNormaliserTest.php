<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\CaseTypeColumns;

use OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnsNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * How a case type's column settings are cleaned before they are stored.
 *
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
class CaseTypeColumnsNormaliserTest extends TestCase {
	public function testAQrColumnKeepsItsLinkText(): void {
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [['field' => 'vergunningsbewijs', 'render' => 'qr', 'linkLabel' => ' Toevoegen aan wallet ']]
		);

		$this->assertSame([['field' => 'vergunningsbewijs', 'render' => 'qr', 'linkLabel' => 'Toevoegen aan wallet']], $clean);
	}//end testAQrColumnKeepsItsLinkText()

	public function testLinkTextIsDroppedOutsideLinkAndQr(): void {
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [
				['field' => 'startdatum', 'render' => 'date', 'linkLabel' => 'Open'],
				['field' => 'website', 'render' => 'link', 'linkLabel' => str_repeat('a', 61)],
			]
		);

		$this->assertSame([['field' => 'startdatum', 'render' => 'date'], ['field' => 'website', 'render' => 'link']], $clean);
	}//end testLinkTextIsDroppedOutsideLinkAndQr()

	public function testAnUnknownRenderOrFieldIsRefused(): void {
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [
				['field' => 'status', 'render' => 'badge'],
				['field' => 'a b', 'render' => 'text'],
				['field' => '', 'render' => 'text'],
				'status',
				['field' => 'status', 'render' => 'text'],
				['field' => 'status', 'render' => 'link'],
			]
		);

		$this->assertSame([['field' => 'status', 'render' => 'text']], $clean);
	}//end testAnUnknownRenderOrFieldIsRefused()

	public function testByTypeLeavesOutATypeWithoutColumns(): void {
		$byType = (new CaseTypeColumnsNormaliser())->byType(
			portal: ['caseTypeColumns' => ['parkeren' => [['field' => 'kenteken', 'render' => 'text']], 'leeg' => [['render' => 'qr']]]]
		);

		$this->assertSame(['parkeren' => [['field' => 'kenteken', 'render' => 'text']]], $byType);
		$this->assertSame([], (new CaseTypeColumnsNormaliser())->byType(portal: null));
	}//end testByTypeLeavesOutATypeWithoutColumns()
}//end class
