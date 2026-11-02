<?php

/**
 * Example data for DqZaakBetrokkenen: the parties of the Lindelaan Woo case,
 * as role records and as links in OpenRegister's party model, each in the
 * Woo case type's role and with its period.
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
 * @spec openspec/specs/example-set-woo-case-parties/spec.md#requirement-the-lindelaan-woo-case-carries-its-parties
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The parties on woo-fietspad.
 *
 * @spec openspec/specs/example-set-woo-case-parties/spec.md#requirement-the-lindelaan-woo-case-carries-its-parties
 */
class ZuiddrechtWooCasePartiesTest extends TestCase {

	/**
	 * Two party links on the case besides Lars, each pointing at its role record's participant, Sanne primary.
	 * J. van Wijk is the organisation's contact person, not a party of his own, and the residents' association
	 * is not a party to this case (DqZaakBetrokkenen draws three parties).
	 *
	 * @return void
	 */
	public function testTheWooCaseCarriesItsParties(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$roles   = array_column(array_filter($set['objects'], static fn (array $o): bool => $o['schema'] === 'role'), null, 'key');
		// The colleague party (Lars, ZuiddrechtLarsColleaguePartyTest) links a colleague object, not a role record.
		$parties = array_diff_key(array_column($set['parties'] ?? [], 'data', 'key'), ['partij-lars-adviseur' => true]);

		$expected = [
			'partij-sanne-verzoeker'           => ['rol-woo-fietspad-verzoeker', '3c0f5a00-0000-4000-a000-0000000c0001', true],
			'partij-vanwijk-belanghebbende'    => ['rol-woo-fietspad-belanghebbende', '3c0f5a00-0000-4000-a000-0000000c0003', false],
		];
		$this->assertSame(expected: array_keys($expected), actual: array_keys($parties));
		foreach ($expected as $key => [$role, $roleType, $primary]) {
			$this->assertArrayHasKey(key: $role, array: $roles);
			$this->assertSame(expected: '{{object:woo-fietspad.id}}', actual: $parties[$key]['objectUuid']);
			$this->assertSame(expected: '{{object:'.$role.'.participant}}', actual: $parties[$key]['partyUuid']);
			$this->assertSame(expected: $roleType, actual: $parties[$key]['role']);
			$this->assertSame(expected: $primary, actual: ($parties[$key]['primary'] ?? false), message: $key);
		}

		$this->assertArrayNotHasKey(key: 'rol-woo-fietspad-gemachtigde', array: $roles);
		$this->assertArrayNotHasKey(key: 'rol-woo-fietspad-melder', array: $roles);
	}//end testTheWooCaseCarriesItsParties()
}//end class
