<?php

/**
 * Example data for DqZaakTaken "Wat hierna komt" on the Lindelaan Woo case:
 * Besluit opstellen is Pieter's, planned by the Woo steps; Termijn controleren
 * is the Woo team's, planned by Pieter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-resident/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The planned actions on the Lindelaan case.
 */
class ZuiddrechtLindelaanPlannedActionsTest extends TestCase {

	/**
	 * Who owns each action and who or what planned it.
	 *
	 * @return void
	 */
	public function testThePlannedActionsSayWhoPlannedThem(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$besluit = $objects['woo-fietspad-gepland-besluit'];
		$this->assertSame(['{{cast:pieter.uid}}', 'de stappen van het Woo-verzoek'], [$besluit['owner'], $besluit['plannedByRule']]);
		$this->assertArrayNotHasKey('plannedBy', $besluit);

		$termijn = $objects['woo-fietspad-gepland-termijn'];
		$this->assertSame(['{{cast:woo-en-informatie.gid}}', '{{cast:pieter.uid}}'], [$termijn['ownerGroup'], $termijn['plannedBy']]);
		// An empty owner, not an absent one: a refresh writes it over an owner an earlier install left.
		$this->assertSame(expected: '', actual: ($termijn['owner'] ?? null));
	}//end testThePlannedActionsSayWhoPlannedThem()
}//end class
