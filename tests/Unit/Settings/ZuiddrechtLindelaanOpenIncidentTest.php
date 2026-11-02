<?php

/**
 * Example data for DqZaakTaken: the Lindelaan Woo case holds one open
 * incident, so the Incidents card shows a row with its state pill: Sanne de
 * Vries reported that the link in Mijn Zuiddrecht does not work, and Pieter
 * Jansen owns it.
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
 * The open incident on the Lindelaan case.
 */
class ZuiddrechtLindelaanOpenIncidentTest extends TestCase {

	/**
	 * One open incident on the Woo case, reported by Sanne and owned by Pieter.
	 *
	 * @return void
	 */
	public function testTheWooCaseHoldsOneOpenIncident(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$incident = ($objects['woo-fietspad-incident-link'] ?? null);
		$this->assertNotNull(actual: $incident);
		$this->assertSame(expected: 'dossiq', actual: $incident['register']);
		$this->assertSame(expected: 'caseIncident', actual: $incident['schema']);
		$this->assertSame(
			expected: [
				'case'        => '{{object:woo-fietspad.id}}',
				// dossiq's caseIncident.eventDate is format date-time; a bare date is refused by the install.
				'eventDate'   => '{{datetime:-9 10:00}}',
				'recordedAt'  => '{{datetime:-9 10:15}}',
				'reporter'    => '{{cast:sanne.displayName}}',
				'description' => 'Verzoeker meldt dat de link in Mijn Zuiddrecht niet werkt',
				'assignee'    => '{{cast:pieter.uid}}',
				'state'       => 'open',
			],
			actual: $incident['data']
		);
	}//end testTheWooCaseHoldsOneOpenIncident()
}//end class
