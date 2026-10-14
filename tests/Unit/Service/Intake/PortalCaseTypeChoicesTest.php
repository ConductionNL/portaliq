<?php

/**
 * Portaliq Portal Case Type Choices Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\PortalCaseTypeChoices;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The choices are the portal's own declared case types, once each, titled.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
 */
#[CoversClass(PortalCaseTypeChoices::class)]
class PortalCaseTypeChoicesTest extends TestCase {

	public function testThePortalsCaseTypesOnceEachByTitle(): void {
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('declaredCaseTypes')->willReturnCallback(
			static fn (string $portal): array => $portal === 'zuiddrecht' ? [
				['dossiq', 'caseType', 'ct-woo'],
				['dossiq', 'caseType', 'ct-parkeren'],
				['dossiq', 'caseType', 'ct-woo'],
				['dossiq', 'caseType', 'ct-gone'],
				['dossiq', 'caseType', 'ct-untitled'],
			] : []
		);
		$asked = [];
		$reader = $this->createMock(CaseTypeReader::class);
		$reader->method('readCaseType')->willReturnCallback(
			static function (string $register, string $schema, string $id) use (&$asked): ?array {
				$asked[] = $id;
				return [
					'ct-woo' => ['title' => 'Woo-verzoek'],
					'ct-parkeren' => ['name' => 'Parkeervergunning'],
					'ct-untitled' => ['title' => '  '],
				][$id] ?? null;
			}
		);
		$choices = new PortalCaseTypeChoices($bindings, $reader);

		$this->assertSame(
			[['id' => 'ct-parkeren', 'title' => 'Parkeervergunning'], ['id' => 'ct-woo', 'title' => 'Woo-verzoek']],
			$choices->forPortal(portal: 'zuiddrecht')
		);
		$this->assertSame(['ct-woo', 'ct-parkeren', 'ct-gone', 'ct-untitled'], $asked, 'a case type is read once');
		$this->assertSame([], $choices->forPortal(portal: 'elders'));
	}//end testThePortalsCaseTypesOnceEachByTitle()
}//end class
