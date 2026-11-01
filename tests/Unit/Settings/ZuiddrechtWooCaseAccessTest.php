<?php

/**
 * Example data for DqZaakBetrokkenen's Toegang card: Sanne's link on the
 * Lindelaan Woo case is a real OpenRegister access link minted by Pieter,
 * dossiq's caseShare record names it, and the case is shared with Juridische
 * zaken by Pieter until the date the board draws.
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
 * @spec openspec/specs/example-set-woo-case-parties/spec.md#requirement-the-lindelaan-woo-case-has-a-read-only-link-for-sanne
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The access link and the grant on woo-fietspad.
 *
 * @spec openspec/specs/example-set-woo-case-parties/spec.md#requirement-the-lindelaan-woo-case-has-a-read-only-link-for-sanne
 */
class ZuiddrechtWooCaseAccessTest extends TestCase {

	/**
	 * Pieter owns the case, so OpenRegister's sharing guard admits him to share it.
	 *
	 * @return void
	 */
	public function testPieterOwnsTheCase(): void {
		$case = array_values(array_filter($this->set()['objects'], static fn (array $o): bool => $o['key'] === 'woo-fietspad'));

		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: ($case[0]['owner'] ?? null));
	}//end testPieterOwnsTheCase()

	/**
	 * One access link for Sanne, read only, minted by Pieter, ending when the caseShare record says ("Verloopt 15 nov").
	 *
	 * @return void
	 */
	public function testSanneHasAMintedAccessLink(): void {
		$links = array_column($this->set()['accessLinks'] ?? [], 'data', 'key');

		$this->assertSame(
			expected: [
				'woo-fietspad-link-sanne' => [
					'object'       => '{{object:woo-fietspad.id}}',
					'capabilities' => ['read'],
					'label'        => '{{cast:sanne.displayName}}',
					'createdBy'    => '{{cast:pieter.uid}}',
					'expiresAt'    => '{{datetime:29 23:59}}',
				],
			],
			actual: $links
		);
	}//end testSanneHasAMintedAccessLink()

	/**
	 * The caseShare record names the minted link by id, uuid and address, so the card's revoke reaches it.
	 *
	 * @return void
	 */
	public function testTheCaseShareNamesItsLink(): void {
		$changes = array_column($this->set()['changes'], null, 'key');

		$this->assertArrayHasKey(key: 'woo-fietspad-link-sanne-names-its-link', array: $changes);
		$change = $changes['woo-fietspad-link-sanne-names-its-link'];
		$this->assertSame(expected: ['dossiq', 'caseShare', 'woo-fietspad-link-sanne'], actual: [$change['register'], $change['schema'], $change['object']]);
		$this->assertSame(
			expected: [
				'accessLinkId'   => '{{accessLink:woo-fietspad-link-sanne.id}}',
				'accessLinkUuid' => '{{accessLink:woo-fietspad-link-sanne.uuid}}',
				'accessLinkUrl'  => '{{accessLink:woo-fietspad-link-sanne.url}}',
			],
			actual: $change['set']
		);

		$shares = array_values(array_filter($this->set()['objects'], static fn (array $o): bool => $o['key'] === 'woo-fietspad-link-sanne'));
		$this->assertSame(expected: '{{datetime:29 23:59}}', actual: $shares[0]['data']['expiresAt'], message: 'the record and the link end together');
	}//end testTheCaseShareNamesItsLink()

	/**
	 * Juridische zaken may read the case, shared by Pieter on 6 Oct (11 days before the board's day) until 31 Oct
	 * (14 days after it).
	 *
	 * @return void
	 */
	public function testJuridischeZakenIsSharedByPieter(): void {
		$grants = array_column($this->set()['grants'] ?? [], 'data', 'key');

		$this->assertSame(
			expected: [
				'woo-fietspad-juridische-zaken' => [
					'object'      => '{{object:woo-fietspad.id}}',
					'type'        => 'group',
					'shareWith'   => '{{cast:juridische-zaken.gid}}',
					'permissions' => 1,
					'sharedBy'    => '{{cast:pieter.uid}}',
					'sharedAt'    => '{{datetime:-11 10:00}}',
					'expiresAt'   => '{{date:14}}',
				],
			],
			actual: $grants
		);
	}//end testJuridischeZakenIsSharedByPieter()

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end set()
}//end class
