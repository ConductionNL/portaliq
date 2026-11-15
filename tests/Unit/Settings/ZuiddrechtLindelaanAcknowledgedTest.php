<?php

/**
 * The Lindelaan Woo case arrives already acknowledged: its acknowledgement duty
 * reads met, at the moment of the seeded "Ontvangstbevestiging verstuurd" entry,
 * so dossiq's automatic acknowledgement leaves it alone and DqZaakHistorie draws
 * the board's ten entries, not an eleventh "Ontvangstbevestiging verzonden".
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
 * The Lindelaan case's acknowledgement duty is met.
 */
class ZuiddrechtLindelaanAcknowledgedTest extends TestCase {

	/**
	 * The set as an array.
	 *
	 * @return array<string, mixed> The set.
	 */
	private function set(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return $set;
	}//end set()

	/**
	 * The duty reads met, through e-mail to Sanne, with no failure.
	 *
	 * @return void
	 */
	public function testTheDutyIsMet(): void {
		$cases = array_column((array)$this->set()['objects'], null, 'key');
		$duty  = ($cases['woo-fietspad']['data']['acknowledgementDuty'] ?? null);
		$this->assertIsArray(actual: $duty);
		$this->assertTrue(condition: $duty['required']);
		$this->assertSame(expected: 'met', actual: $duty['status']);
		$this->assertSame(expected: 'email', actual: $duty['channel']);
		$this->assertSame(expected: '{{cast:sanne.email}}', actual: $duty['recipient']);
		$this->assertSame(expected: '', actual: $duty['lastError']);
	}//end testTheDutyIsMet()

	/**
	 * It was met when the seeded history says the acknowledgement went out.
	 *
	 * @return void
	 */
	public function testItWasMetWhenTheHistorySaysSo(): void {
		$set     = $this->set();
		$cases   = array_column((array)$set['objects'], null, 'key');
		$entries = array_column((array)$set['timelineEntries'], 'data', 'key');
		$sent    = $entries['woo-fietspad-historie-ontvangstbevestiging'];
		$this->assertSame(expected: 'Ontvangstbevestiging verstuurd', actual: $sent['message']);
		$this->assertSame(expected: $sent['at'], actual: $cases['woo-fietspad']['data']['acknowledgementDuty']['sentAt']);
	}//end testItWasMetWhenTheHistorySaysSo()
}//end class
