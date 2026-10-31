<?php

/**
 * Example data for the DqZaak boards: Pieter Jansen of the team Woo en
 * informatie handles the Lindelaan Woo case and reviews its documents.
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
 *
 * @spec openspec/specs/example-set-lindelaan-woo-team/spec.md#requirement-pieter-jansen-of-woo-en-informatie-handles-the-lindelaan-woo-case
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The handler and team the DqZaak boards draw on the Woo case.
 *
 * @spec openspec/specs/example-set-lindelaan-woo-team/spec.md#requirement-pieter-jansen-of-woo-en-informatie-handles-the-lindelaan-woo-case
 */
class ZuiddrechtLindelaanHandlerTest extends TestCase {

	/**
	 * Pieter handles the case for Woo en informatie: its tasks and its conversation with Sanne are his.
	 *
	 * @return void
	 */
	public function testPieterHandlesTheWooCaseForTheWooTeam(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		$objects = array_column($set['objects'], 'data', 'key');
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $objects['woo-fietspad']['assignee']);
		$this->assertSame(expected: 'zuiddrecht-woo-en-informatie', actual: $objects['woo-fietspad']['assignedGroup']);

		$tasks = array_column($set['tasks'], 'data', 'key');
		foreach (['woo-fietspad-taak-beoordelen', 'woo-fietspad-taak-compleet', 'woo-fietspad-taak-ontvangst'] as $key) {
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $tasks[$key]['assignee'], message: $key);
		}

		foreach (['woo-fietspad-telefoon', 'woo-fietspad-uitleg', 'woo-fietspad-reactie', 'woo-fietspad-aanvulling'] as $key) {
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $objects[$key]['kccEmployeeId'], message: $key);
			$this->assertSame(expected: '{{cast:sanne.bsn}}', actual: $objects[$key]['geidentificeerdeBurgerId'], message: $key);
			$this->assertSame(expected: '{{lookup:sanne}}', actual: ($objects[$key]['contact'] ?? null), message: $key);
		}
	}//end testPieterHandlesTheWooCaseForTheWooTeam()
}//end class
