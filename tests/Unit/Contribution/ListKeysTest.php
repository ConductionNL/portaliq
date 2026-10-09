<?php

/**
 * A collection block may open each row's own page, show tabs, and read as
 * the boards' lists, through the whole manifest normaliser
 * (mijn-lists-follow-the-boards).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-row-may-open-its-own-page
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
 */
class ListKeysTest extends TestCase {

	/**
	 * Run one block through the real manifest normaliser.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed> The surviving block.
	 */
	private function block(array $block): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'cijfers', 'schema' => 'grade', 'fields' => ['vak', 'vakId', 'cijfer', 'datum', 'weging', 'docent', 'nieuw', 'toets', 'status']],
				],
				'pages'       => [
					['id' => 'lijst', 'label' => 'Cijfers', 'blocks' => [$block]],
					['id' => 'vak', 'label' => 'Vak', 'blocks' => [['type' => 'collection', 'collection' => 'cijfers']]],
				],
			]
		);

		return $out['pages'][0]['blocks'][0];
	}//end block()

	/**
	 * Grouped chips keep their fields, the summary and the row page with its id field.
	 *
	 * @return void
	 */
	public function testGroupedChipsKeepTheirKeys(): void {
		$block = $this->block([
			'type'        => 'collection',
			'collection'  => 'cijfers',
			'display'     => 'chips',
			'groupField'  => 'vak',
			'valueField'  => 'cijfer',
			'dateField'   => 'datum',
			'weightField' => 'weging',
			'subtitleField' => 'docent',
			'newField'    => 'nieuw',
			'lowBelow'    => 5.5,
			'summary'     => true,
			'summaryText' => 'Je staat {pass} vakken voldoende.',
			'rowPage'     => 'vak',
			'rowIdField'  => 'vakId',
		]);

		foreach (['groupField' => 'vak', 'valueField' => 'cijfer', 'weightField' => 'weging', 'subtitleField' => 'docent', 'rowPage' => 'vak', 'rowIdField' => 'vakId'] as $key => $value) {
			$this->assertSame($value, $block[$key], $key);
		}

		$this->assertTrue($block['summary']);
		$this->assertSame('Je staat {pass} vakken voldoende.', $block['summaryText']);
	}//end testGroupedChipsKeepTheirKeys()

	/**
	 * Rows keep the board keys; a choice, field or page that does not fit is dropped.
	 *
	 * @return void
	 */
	public function testRowsKeepTheBoardKeysAndDropWhatDoesNotFit(): void {
		$block = $this->block([
			'type'         => 'collection',
			'collection'   => 'cijfers',
			'display'      => 'rows',
			'valueField'   => 'cijfer',
			'eyebrowField' => 'toets',
			'newField'     => 'onbekend',
			'dateField'    => 'datum',
			'dateDisplay'  => 'line',
			'dateLabel'    => 'Geldig tot',
			'rowStyle'     => 'lines',
			'rowPage'      => 'nergens',
			'rowIdField'   => 'vakId',
		]);

		$this->assertSame('cijfer', $block['valueField']);
		$this->assertSame('toets', $block['eyebrowField']);
		$this->assertSame('line', $block['dateDisplay']);
		$this->assertSame('Geldig tot', $block['dateLabel']);
		$this->assertSame('lines', $block['rowStyle']);
		$this->assertArrayNotHasKey('newField', $block);
		$this->assertArrayNotHasKey('rowPage', $block);
		$this->assertArrayNotHasKey('rowIdField', $block);

		$odd = $this->block(['type' => 'collection', 'collection' => 'cijfers', 'display' => 'rows', 'dateDisplay' => 'calendar', 'rowStyle' => 'boxes']);
		$this->assertArrayNotHasKey('dateDisplay', $odd);
		$this->assertArrayNotHasKey('rowStyle', $odd);
	}//end testRowsKeepTheBoardKeysAndDropWhatDoesNotFit()

	/**
	 * Tabs: a field tab needs a projected field and values, a plain tab only
	 * a label; one tab alone is no tabs; at most six.
	 *
	 * @return void
	 */
	public function testTabsAreKeptWhenThereAreAtLeastTwo(): void {
		$block = $this->block([
			'type'       => 'collection',
			'collection' => 'cijfers',
			'display'    => 'rows',
			'tabs'       => [
				['label' => 'Komend', 'field' => 'status', 'values' => ['confirmed', '', 7]],
				['label' => 'Kapot', 'field' => 'onbekend', 'values' => ['x']],
				['label' => 'Leeg', 'field' => 'status', 'values' => []],
				['label' => ' Alles '],
				['label' => str_repeat('x', 41)],
			],
		]);
		$this->assertSame(
			[['label' => 'Komend', 'field' => 'status', 'values' => ['confirmed']], ['label' => 'Alles']],
			$block['tabs']
		);

		$one = $this->block(['type' => 'collection', 'collection' => 'cijfers', 'tabs' => [['label' => 'Alles']]]);
		$this->assertArrayNotHasKey('tabs', $one);

		$many = $this->block(['type' => 'collection', 'collection' => 'cijfers', 'tabs' => array_fill(0, 8, ['label' => 'Tab'])]);
		$this->assertCount(6, $many['tabs']);
	}//end testTabsAreKeptWhenThereAreAtLeastTwo()
}//end class
