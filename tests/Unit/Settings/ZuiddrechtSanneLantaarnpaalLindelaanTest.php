<?php

/**
 * Example data for DqContact: Sanne de Vries's earlier report "Kapotte
 * lantaarnpaal Lindelaan", a closed Melding openbare ruimte handled by Anouk
 * Bakker, as the board lists it beside her Woo request. The Kerkplein report
 * stays as it is.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Sanne's closed lamp-post report on the Lindelaan.
 */
class ZuiddrechtSanneLantaarnpaalLindelaanTest extends TestCase {

	/**
	 * A closed report of hers, handled by Anouk, beside the unchanged Kerkplein one.
	 *
	 * @return void
	 */
	public function testSanneHasAClosedLampPostReportOnTheLindelaan(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$case = ($objects['melding-lantaarnpaal-lindelaan'] ?? null);
		$this->assertNotNull(actual: $case);
		$this->assertSame(expected: 'case', actual: $case['schema']);
		$this->assertSame(expected: 'melding-openbare-ruimte', actual: $case['lookups']['caseType']['where']['identifier']);
		$this->assertSame(expected: 'Afgehandeld', actual: $case['lookups']['status']['where']['name']);
		$this->assertSame(
			expected: ['Kapotte lantaarnpaal Lindelaan', '{{lookup:sanne}}', '{{cast:sanne.uid}}', '{{cast:anouk.uid}}'],
			actual: [$case['data']['title'], $case['data']['requester'], $case['data']['portalSubject'], $case['data']['assignee']]
		);

		$this->assertSame(expected: 'Kapotte lantaarnpaal Kerkplein', actual: $objects['melding-lantaarnpaal']['data']['title']);
	}//end testSanneHasAClosedLampPostReportOnTheLindelaan()
}//end class
