<?php

/**
 * Example data for DqZaakBetrokkenen: Lars de Koning advises on the Lindelaan
 * Woo case as a colleague, a party of kind colleague (dossiq's colleague
 * schema), in the role Adviseur until the end of the month.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/example-set-portaal/specs/example-set-portaal/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Lars as a colleague party on the Lindelaan case.
 */
class ZuiddrechtLarsColleaguePartyTest extends TestCase {

	/**
	 * A colleague object for Lars from the cast, declared before the link, and the link in the role Adviseur.
	 *
	 * @return void
	 */
	public function testLarsAdvisesOnTheLindelaanCaseAsAColleague(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');
		$parties = array_column($set['parties'], 'data', 'key');

		$lars = $objects['collega-lars'] ?? null;
		$this->assertNotNull($lars);
		$this->assertSame(['dossiq', 'colleague'], [$lars['register'], $lars['schema']]);
		$this->assertSame('{{cast:lars.uid}}', $lars['data']['userId']);
		$this->assertSame('{{cast:lars.displayName}}', $lars['data']['displayName']);
		$this->assertSame('Mobiliteit en verkeer', $lars['data']['department']);

		$link = $parties['partij-lars-adviseur'] ?? null;
		$this->assertNotNull($link);
		$this->assertSame('{{object:woo-fietspad.id}}', $link['objectUuid']);
		$this->assertSame('{{object:collega-lars.id}}', $link['partyUuid']);
		$this->assertSame('3c0f5a00-0000-4000-a000-0000000c0004', $link['role']);
		$this->assertArrayHasKey('validUntil', $link);
	}//end testLarsAdvisesOnTheLindelaanCaseAsAColleague()

	/**
	 * The request covers documents from last year to this year, as the Het verzoek pill reads ("Periode 2025 tot en met
	 * 2026"): the period starts more than a year before the install day.
	 *
	 * @return void
	 */
	public function testTheRequestPeriodStartsInTheYearBefore(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');
		$period  = $objects['woo-fietspad']['data']['wooRequest'];

		$this->assertMatchesRegularExpression('/^\{\{date:-(\d+)\}\}$/', $period['periodeVan']);
		preg_match('/-(\d+)/', $period['periodeVan'], $days);
		$this->assertGreaterThanOrEqual(366, (int) $days[1]);
	}//end testTheRequestPeriodStartsInTheYearBefore()

	/**
	 * DqZaakBesluiten's Beschikking card reads the case's decision text (besluitDocument): the concept Woo decision,
	 * deels openbaar, naming the refusal grounds the assessed documents carry.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesItsConceptDecisionLetter(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');
		$letter  = (string) ($objects['woo-fietspad']['data']['besluitDocument'] ?? '');

		$this->assertStringContainsString('deels openbaar', $letter);
		$this->assertStringContainsString('5.1.2', $letter);
		$this->assertStringContainsString('5.2.1', $letter);
		$this->assertStringNotContainsString('{{', $letter, 'the letter holds no placeholder the installer would read as a set token');
	}//end testTheCaseCarriesItsConceptDecisionLetter()
}//end class
