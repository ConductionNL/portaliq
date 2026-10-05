<?php

/**
 * The school displays survive the whole manifest normaliser with exactly the
 * keys the contract names, and a field the collection does not project is
 * dropped (site-school-blocks wave 2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md
 */
class SchoolBlockKeysTest extends TestCase {

	/**
	 * Run blocks through the real manifest normaliser on one page.
	 *
	 * @param array<int, array<string, mixed>> $blocks The declared blocks.
	 *
	 * @return array<int, array<string, mixed>> The surviving blocks, the guard text left out.
	 */
	private function blocks(array $blocks): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'meldingen', 'schema' => 'excuse', 'fields' => ['datum', 'kind', 'reden', 'toelichting', 'status', 'gezien']],
					['id' => 'cijfers', 'schema' => 'grade', 'fields' => ['vak', 'cijfer', 'cijfers', 'gemiddelde', 'opmerking']],
					['id' => 'uren', 'schema' => 'hours', 'fields' => ['goedgekeurd', 'wachtend', 'terug', 'doel']],
					['id' => 'taken', 'schema' => 'task', 'fields' => ['titel', 'wanneer', 'deadline']],
					['id' => 'agenda', 'schema' => 'event', 'fields' => ['start', 'titel', 'wie']],
				],
				'actions'     => [['id' => 'createExcuseRequest', 'type' => 'create', 'schema' => 'excuse']],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'blocks' => array_merge($blocks, [['type' => 'richText', 'markdown' => 'Welkom']]),
					],
				],
			]
		);

		return array_values(array_filter($out['pages'][0]['blocks'], static fn (array $b): bool => $b['type'] !== 'richText'));
	}//end blocks()

	public function testRowsKeepTheirProjectedFieldsOnly(): void {
		$this->assertSame(
			[[
				'type' => 'collection', 'collection' => 'meldingen', 'display' => 'rows',
				'dateField' => 'datum', 'quoteField' => 'toelichting', 'statusField' => 'status', 'statusNoteField' => 'gezien',
				'titleFields' => ['kind', 'reden'],
			]],
			$this->blocks([[
				'type' => 'collection', 'collection' => 'meldingen', 'display' => 'rows', 'dateField' => 'datum',
				'titleFields' => ['kind', 'geheim', 'reden'], 'subtitleField' => 'bsn', 'quoteField' => 'toelichting',
				'statusField' => 'status', 'statusNoteField' => 'gezien',
			]])
		);
	}//end testRowsKeepTheirProjectedFieldsOnly()

	public function testBarsAndChipsKeepTheirNumbersWithinBounds(): void {
		$this->assertSame(
			[['type' => 'collection', 'collection' => 'cijfers', 'display' => 'bars', 'labelField' => 'vak', 'valueField' => 'cijfer', 'noteField' => 'opmerking', 'noteLabel' => 'Van de leerkracht', 'max' => 10]],
			$this->blocks([['type' => 'collection', 'collection' => 'cijfers', 'display' => 'bars', 'labelField' => 'vak', 'valueField' => 'cijfer', 'noteField' => 'opmerking', 'noteLabel' => 'Van de leerkracht', 'max' => 10]])
		);
		$chips = $this->blocks([['type' => 'collection', 'collection' => 'cijfers', 'display' => 'chips', 'labelField' => 'vak', 'valuesField' => 'cijfers', 'averageField' => 'gemiddelde', 'lowBelow' => 5.5]]);
		$this->assertSame(5.5, $chips[0]['lowBelow']);
		$this->assertSame('cijfers', $chips[0]['valuesField']);

		$bad = $this->blocks([['type' => 'collection', 'collection' => 'cijfers', 'display' => 'bars', 'labelField' => 'vak', 'max' => -1]]);
		$this->assertArrayNotHasKey('max', $bad[0], 'a maximum below zero is dropped');
		$this->assertSame(
			[['type' => 'collection', 'collection' => 'cijfers']],
			$this->blocks([['type' => 'collection', 'collection' => 'cijfers', 'display' => 'carousel', 'labelField' => 'vak']]),
			'an unknown display adds nothing'
		);
	}//end testBarsAndChipsKeepTheirNumbersWithinBounds()

	public function testCardsGainAStatusANoteAndAComingUpPart(): void {
		$cards = $this->blocks([[
			'type' => 'collection', 'collection' => 'meldingen', 'display' => 'cards', 'titleFields' => ['kind'],
			'subtitleFields' => ['reden'], 'statusField' => 'status', 'noteField' => 'gezien', 'soonField' => 'toelichting',
			'soonLabel' => 'Binnenkort', 'avatar' => true, 'statusTones' => ['present' => 'success', 'sick' => 'warning', 'odd' => 'purple', 3 => 'error'],
		]])[0];

		$this->assertSame('cards', $cards['display']);
		$this->assertSame(['kind'], $cards['titleFields']);
		$this->assertSame(['reden'], $cards['subtitleFields']);
		$this->assertSame('status', $cards['statusField']);
		$this->assertSame('Binnenkort', $cards['soonLabel']);
		$this->assertTrue($cards['avatar']);
		$this->assertSame(['present' => 'success', 'sick' => 'warning'], $cards['statusTones'], 'an unknown tone or a numeric value is dropped');
	}//end testCardsGainAStatusANoteAndAComingUpPart()

	public function testTasksTakeTheHighlightCard(): void {
		$this->assertSame(
			[['type' => 'tasks', 'collection' => 'taken', 'display' => 'highlight', 'eyebrow' => 'Eerst dit', 'buttonLabel' => 'Tijd kiezen', 'subtitleFields' => ['wanneer']]],
			$this->blocks([['type' => 'tasks', 'collection' => 'taken', 'display' => 'highlight', 'eyebrow' => 'Eerst dit', 'buttonLabel' => 'Tijd kiezen', 'subtitleFields' => ['wanneer', 'geheim']]])
		);
		$this->assertSame(
			[['type' => 'tasks', 'collection' => 'taken']],
			$this->blocks([['type' => 'tasks', 'collection' => 'taken', 'eyebrow' => 'Eerst dit']]),
			'the highlight keys come only with the highlight display'
		);
	}//end testTasksTakeTheHighlightCard()

	public function testASegmentedKpiMayStandWithoutCards(): void {
		$kpi = $this->blocks([[
			'type' => 'kpi', 'collection' => 'uren', 'display' => 'segmented', 'target' => 480, 'unit' => 'uur',
			'segments' => [
				['field' => 'goedgekeurd', 'label' => 'Goedgekeurd', 'tone' => 'positive'],
				['field' => 'wachtend', 'label' => 'Wacht op het bedrijf', 'tone' => 'waiting'],
				['field' => 'terug', 'label' => 'Teruggestuurd', 'tone' => 'loud'],
				['label' => 'zonder veld'],
			],
		]]);

		$this->assertCount(1, $kpi);
		$this->assertSame('segmented', $kpi[0]['display']);
		$this->assertCount(3, $kpi[0]['segments']);
		$this->assertSame('positive', $kpi[0]['segments'][2]['tone'], 'an unknown tone reads as positive');
		$this->assertSame(480, $kpi[0]['target']);
		$this->assertSame([], $kpi[0]['cards']);

		$this->assertSame([], $this->blocks([['type' => 'kpi', 'collection' => 'uren', 'display' => 'segmented', 'segments' => []]]), 'no cards and no segments: no block');
	}//end testASegmentedKpiMayStandWithoutCards()

	public function testACalendarMayShowTilesAndASubLine(): void {
		$calendar = $this->blocks([['type' => 'calendar', 'display' => 'tiles', 'sources' => [['collection' => 'agenda', 'startField' => 'start', 'titleField' => 'titel', 'metaField' => 'wie']]]])[0];

		$this->assertSame('tiles', $calendar['display']);
		$this->assertSame('wie', $calendar['sources'][0]['metaField']);
	}//end testACalendarMayShowTilesAndASubLine()

	public function testAGreetingKeepsOneTargetThatResolves(): void {
		$this->assertSame(
			[['type' => 'greeting', 'showDate' => true, 'action' => 'createExcuseRequest', 'label' => 'Afwezig melden']],
			$this->blocks([['type' => 'greeting', 'action' => 'createExcuseRequest', 'label' => 'Afwezig melden']])
		);
		$this->assertSame(
			[['type' => 'greeting', 'showDate' => false]],
			$this->blocks([['type' => 'greeting', 'showDate' => false, 'route' => '//elders.example', 'label' => 'Weg']]),
			'an outside route is dropped and the greeting stays'
		);
		$this->assertSame(
			[['type' => 'greeting', 'showDate' => true, 'page' => 'overzicht', 'label' => 'Naar het overzicht']],
			$this->blocks([['type' => 'greeting', 'page' => 'overzicht', 'label' => 'Naar het overzicht', 'withRecord' => true]])
		);
	}//end testAGreetingKeepsOneTargetThatResolves()
}//end class
