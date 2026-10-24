<?php

/**
 * Example data for DqZaakEmail's notes on the Lindelaan Woo case: Lars's
 * note in the morning and Pieter's note to Anouk in the afternoon.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The notes on the Lindelaan case.
 */
class ZuiddrechtLindelaanNotesTest extends TestCase {

	/**
	 * Two notes on the case, by Lars and by Pieter (who mentions Anouk), on the same day.
	 *
	 * @return void
	 */
	public function testTheCaseHasLarsAndPietersNotes(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$notes = array_column($set['notes'], 'data', 'key');

		$this->assertSame(expected: ['lars-noteert-fietspad', 'pieter-noteert-fietspad-anouk'], actual: array_keys($notes));
		foreach ($notes as $note) {
			$this->assertSame(expected: '{{object:woo-fietspad.id}}', actual: $note['objectUuid']);
		}

		$this->assertStringStartsWith(prefix: '@Anouk Bakker', string: $notes['pieter-noteert-fietspad-anouk']['message']);
	}//end testTheCaseHasLarsAndPietersNotes()
}//end class
