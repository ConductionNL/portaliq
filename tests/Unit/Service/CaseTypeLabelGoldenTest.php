<?php

/**
 * CaseTypeLabelGoldenTest
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseTypeNames;
use OCA\Portaliq\Service\PortalCaseTypeCatalogue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The name the case type names and the portal's case type catalogue read
 * off a case type, pinned per case type shape and label field.
 *
 * @covers \OCA\Portaliq\Service\CaseTypeNames
 * @covers \OCA\Portaliq\Service\PortalCaseTypeCatalogue
 * @uses   \OCA\Portaliq\Service\CaseTypeLabel
 */
class CaseTypeLabelGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/case-type-label.json';

	/**
	 * Every label matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryLabelMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->types() as $typeName => $type) {
			foreach (['' => 'no label field', 'naam' => 'label field naam', 'title' => 'label field title'] as $labelField => $fieldName) {
				foreach ([CaseTypeNames::class, PortalCaseTypeCatalogue::class] as $class) {
					$reflection = new ReflectionClass(objectOrClass: $class);
					$reader     = $reflection->newInstanceWithoutConstructor();
					$actual[$typeName . ' | ' . $fieldName][$reflection->getShortName()] = $reflection->getMethod(name: 'labelOf')->invoke(
						$reader,
						$type,
						$labelField
					);
				}
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string)file_get_contents(self::GOLDEN), true), actual: $actual);
	}//end testEveryLabelMatchesTheGolden()

	/**
	 * Case types by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function types(): array {
		return [
			'empty' => [],
			'every field' => ['naam' => 'Eigen', 'title' => 'Titel', 'name' => 'Naam', 'label' => 'Label', 'omschrijving' => 'Omschrijving'],
			'name only' => ['name' => '  Naam  '],
			'label only' => ['label' => 'Label'],
			'omschrijving only' => ['omschrijving' => 'Omschrijving'],
			'blank title' => ['title' => '   ', 'name' => 'Naam'],
			'non-string title' => ['title' => 42, 'label' => 'Label'],
			'blank label field' => ['naam' => '', 'title' => 'Titel'],
			'unknown field only' => ['code' => 'X1'],
		];
	}//end types()
}//end class
