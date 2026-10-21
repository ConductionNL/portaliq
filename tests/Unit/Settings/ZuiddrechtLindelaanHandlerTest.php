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
 * @spec openspec/changes/example-set-lindelaan-woo-team/specs/example-set-lindelaan-woo-team/spec.md#requirement-pieter-jansen-of-woo-en-informatie-handles-the-lindelaan-woo-case
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The handler and team the DqZaak boards draw on the Woo case.
 *
 * @spec openspec/changes/example-set-lindelaan-woo-team/specs/example-set-lindelaan-woo-team/spec.md#requirement-pieter-jansen-of-woo-en-informatie-handles-the-lindelaan-woo-case
 */
class ZuiddrechtLindelaanHandlerTest extends TestCase {

	/**
	 * Pieter handles the case for Woo en informatie, and the review task is his.
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
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $tasks['woo-fietspad-taak-beoordelen']['assignee']);
	}//end testPieterHandlesTheWooCaseForTheWooTeam()
}//end class
