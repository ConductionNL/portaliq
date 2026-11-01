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
	 * Four party links on the case besides Lars, each pointing at its role record's participant, Sanne primary, as
	 * DqZaakBetrokkenen draws five parties: J. van Wijk acts for the contractor and the residents' association reported
	 * the dark cycle path.
	 *
	 * @return void
	 */
	public function testTheWooCaseCarriesItsParties(): void {
		$set     = $this->set();
		$roles   = array_column(array_filter($set['objects'], static fn (array $o): bool => $o['schema'] === 'role'), null, 'key');
		// The colleague party (Lars, ZuiddrechtLarsColleaguePartyTest) links a colleague object, not a role record.
		$parties = array_diff_key(array_column($set['parties'] ?? [], 'data', 'key'), ['partij-lars-adviseur' => true]);

		$expected = [
			'partij-sanne-verzoeker'          => ['rol-woo-fietspad-verzoeker', '3c0f5a00-0000-4000-a000-0000000c0001', true],
			'partij-vanwijk-belanghebbende'   => ['rol-woo-fietspad-belanghebbende', '3c0f5a00-0000-4000-a000-0000000c0003', false],
			'partij-jvanwijk-gemachtigde'     => ['rol-woo-fietspad-gemachtigde', '3c0f5a00-0000-4000-a000-0000000c0002', false],
			'partij-bewonerslindelaan-melder' => ['rol-woo-fietspad-melder', '3c0f5a00-0000-4000-a000-0000000c0005', false],
		];
		$this->assertSame(expected: array_keys($expected), actual: array_keys($parties));
		foreach ($expected as $key => [$role, $roleType, $primary]) {
			$this->assertArrayHasKey(key: $role, array: $roles);
			$this->assertSame(expected: '{{object:woo-fietspad.id}}', actual: $parties[$key]['objectUuid']);
			$this->assertSame(expected: '{{object:'.$role.'.participant}}', actual: $parties[$key]['partyUuid']);
			$this->assertSame(expected: $roleType, actual: $parties[$key]['role']);
			$this->assertSame(expected: $primary, actual: ($parties[$key]['primary'] ?? false), message: $key);
		}

		$this->assertSame(expected: '{{lookup:vanwijk}}', actual: $roles['rol-woo-fietspad-gemachtigde']['data']['representedParty']);
	}//end testTheWooCaseCarriesItsParties()

	/**
	 * Each party row carries the details line and the period the board draws: the note is the line after the kind
	 * word ("Contact", "Organisatie", "Collega"), and a link that ends shows only its end ("Tot 31 okt", "Tot 31 dec"),
	 * so it has no start. The board's day is the case's start plus fourteen days.
	 *
	 * @return void
	 */
	public function testEachPartyRowHasTheBoardsDetailsAndPeriod(): void {
		$parties = array_column($this->set()['parties'] ?? [], 'data', 'key');

		$expected = [
			'partij-lars-adviseur'            => ['{{cast:lars.department}} · {{cast:lars.email}}', null, '{{date:14}}'],
			'partij-sanne-verzoeker'          => ['{{cast:sanne.address.street}} {{cast:sanne.address.number}} · ingelogd met DigiD', '{{date:-14}}', null],
			'partij-vanwijk-belanghebbende'   => ['KvK {{cast:vanwijk.kvk}} · contactpersoon {{cast:jvanwijk.displayName}}', '{{date:-11}}', null],
			'partij-jvanwijk-gemachtigde'     => ['Machtiging namens {{cast:vanwijk.name}} · Awb 2:1', null, '{{date:75}}'],
			'partij-bewonerslindelaan-melder' => ['Melder zonder account · via de balie', '{{date:-14}}', null],
		];
		foreach ($expected as $key => [$note, $from, $until]) {
			$this->assertSame(expected: $note, actual: ($parties[$key]['note'] ?? null), message: $key.' note');
			$this->assertSame(expected: $from, actual: ($parties[$key]['validFrom'] ?? null), message: $key.' validFrom');
			$this->assertSame(expected: $until, actual: ($parties[$key]['validUntil'] ?? null), message: $key.' validUntil');
		}

		// A link that once had a start loses it on a refresh only when the set names the start as null.
		$this->assertArrayHasKey(key: 'validFrom', array: $parties['partij-lars-adviseur']);
		$this->assertArrayHasKey(key: 'validFrom', array: $parties['partij-jvanwijk-gemachtigde']);
	}//end testEachPartyRowHasTheBoardsDetailsAndPeriod()

	/**
	 * Rollen prints each party's role line from its role record's description, while Partijen prints the link note:
	 * "Primaire partij · verzoeker", "Gemachtigde namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1", "Melder zonder
	 * account · via de balie", "Adviseur · Mobiliteit en verkeer", "Belanghebbende · KvK 00001005". A description in
	 * lower case continues the role word; Lars has a role record too, so his line has its department.
	 *
	 * @return void
	 */
	public function testEachRoleRecordCarriesTheRollenLine(): void {
		$roles = array_column(array_filter($this->set()['objects'], static fn (array $o): bool => $o['schema'] === 'role'), null, 'key');

		$expected = [
			'rol-woo-fietspad-verzoeker'      => '',
			'rol-woo-fietspad-belanghebbende' => 'KvK {{cast:vanwijk.kvk}}',
			'rol-woo-fietspad-gemachtigde'    => 'namens {{cast:vanwijk.name}} · Awb 2:1',
			'rol-woo-fietspad-melder'         => 'zonder account · via de balie',
			'rol-woo-fietspad-adviseur'       => '{{cast:lars.department}}',
		];
		foreach ($expected as $key => $description) {
			$this->assertSame(expected: $description, actual: ($roles[$key]['data']['description'] ?? null), message: $key);
			$this->assertSame(expected: '{{object:woo-fietspad.id}}', actual: $roles[$key]['data']['case'], message: $key);
		}

		$this->assertSame(expected: '{{object:collega-lars.id}}', actual: $roles['rol-woo-fietspad-adviseur']['data']['participant']);
		$this->assertSame(expected: 'Adviseur', actual: $roles['rol-woo-fietspad-adviseur']['lookups']['roleType']['where']['name']);
	}//end testEachRoleRecordCarriesTheRollenLine()

	/**
	 * The Toegang card lists one read-only link for Sanne de Vries, made by Pieter, that ends 29 days after the
	 * board's day ("Verloopt 15 nov"): dossiq reads a case's links as its caseShare records of type link.
	 *
	 * @return void
	 */
	public function testSanneHasAReadOnlyLinkThatEnds(): void {
		$shares = array_values(array_filter($this->set()['objects'], static fn (array $o): bool => $o['schema'] === 'caseShare'));

		$this->assertCount(expectedCount: 1, haystack: $shares);
		$this->assertSame(expected: ['woo-fietspad-link-sanne', 'dossiq'], actual: [$shares[0]['key'], $shares[0]['register']]);
		$this->assertSame(
			expected: [
				'caseId'          => '{{object:woo-fietspad.id}}',
				'shareType'       => 'link',
				'permissionLevel' => 'bekijken',
				'label'           => '{{cast:sanne.displayName}}',
				'capabilities'    => 'read',
				'status'          => 'active',
				'createdBy'       => '{{cast:pieter.uid}}',
				'expiresAt'       => '{{datetime:29 23:59}}',
			],
			actual: $shares[0]['data']
		);
	}//end testSanneHasAReadOnlyLinkThatEnds()

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end set()
}//end class
