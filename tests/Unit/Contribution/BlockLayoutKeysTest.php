<?php

/**
 * Any block may stand in a column, in a frame and carry a link to all of it,
 * through the whole manifest normaliser (mijn-overview-follows-the-boards).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-stand-in-a-column-and-in-a-frame
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/mijn-overview-follows-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-block-may-carry-a-link-to-all-of-it
 */
class BlockLayoutKeysTest extends TestCase {

	/**
	 * Run blocks through the real manifest normaliser on one page.
	 *
	 * @param array<int, array<string, mixed>> $blocks The declared blocks.
	 *
	 * @return array<int, array<string, mixed>> The surviving blocks.
	 */
	private function blocks(array $blocks): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'cijfers', 'schema' => 'grade', 'fields' => ['vak', 'cijfer', 'datum']],
					['id' => 'les', 'schema' => 'lesson', 'fields' => ['start', 'vak']],
				],
				'pages'       => [
					['id' => 'overzicht', 'label' => 'Overzicht', 'blocks' => $blocks],
					['id' => 'cijferlijst', 'label' => 'Cijfers', 'blocks' => [['type' => 'collection', 'collection' => 'cijfers']]],
				],
			]
		);

		return $out['pages'][0]['blocks'];
	}//end blocks()

	/**
	 * A column, a frame and a link that resolve are kept.
	 *
	 * @return void
	 */
	public function testColumnFrameAndLinkAreKept(): void {
		$blocks = $this->blocks([
			[
				'type'       => 'collection',
				'collection' => 'cijfers',
				'column'     => 'side',
				'frame'      => true,
				'more'       => ['label' => 'Alle cijfers', 'page' => 'cijferlijst', 'placement' => 'end'],
			],
			['type' => 'richText', 'markdown' => 'Hallo', 'column' => 'main', 'frame' => 'tinted', 'more' => ['label' => 'Hele week', 'route' => '/mijn/rooster']],
		]);

		$this->assertSame('side', $blocks[0]['column']);
		$this->assertSame('line', $blocks[0]['frame']);
		$this->assertSame(['page' => 'cijferlijst', 'label' => 'Alle cijfers', 'placement' => 'end'], $blocks[0]['more']);
		$this->assertSame('main', $blocks[1]['column']);
		$this->assertSame('tinted', $blocks[1]['frame']);
		$this->assertSame(['route' => '/mijn/rooster', 'label' => 'Hele week'], $blocks[1]['more']);
	}//end testColumnFrameAndLinkAreKept()

	/**
	 * A column, frame or link that does not fit is dropped; the block stays.
	 *
	 * @return void
	 */
	public function testWhatDoesNotFitIsDropped(): void {
		$blocks = $this->blocks([
			[
				'type'       => 'collection',
				'collection' => 'cijfers',
				'column'     => 'left',
				'frame'      => 'shadow',
				'more'       => ['label' => 'Alle cijfers', 'page' => 'onbekend'],
			],
			['type' => 'richText', 'markdown' => 'x', 'more' => ['label' => 'Weg', 'route' => 'https://elders.example']],
			['type' => 'richText', 'markdown' => 'y', 'more' => ['label' => '', 'route' => '/mijn']],
			['type' => 'richText', 'markdown' => 'z', 'more' => ['label' => 'Twee', 'route' => '/mijn', 'page' => 'cijferlijst']],
		]);

		$this->assertCount(4, $blocks);
		foreach ($blocks as $block) {
			$this->assertArrayNotHasKey('column', $block);
			$this->assertArrayNotHasKey('frame', $block);
			$this->assertArrayNotHasKey('more', $block);
		}
	}//end testWhatDoesNotFitIsDropped()

	/**
	 * A greeting may name the week, a kpi block may draw its figures as a
	 * strip with words after each figure.
	 *
	 * @return void
	 */
	public function testGreetingWeekAndKpiStrip(): void {
		$blocks = $this->blocks([
			['type' => 'greeting', 'showWeek' => true],
			['type' => 'greeting', 'showWeek' => 'ja'],
			[
				'type'       => 'kpi',
				'collection' => 'cijfers',
				'display'    => 'strip',
				'cards'      => [['field' => 'cijfer', 'label' => 'Afwezig', 'stripLabel' => ' ziek ']],
			],
		]);

		$this->assertTrue($blocks[0]['showWeek']);
		$this->assertArrayNotHasKey('showWeek', $blocks[1]);
		$this->assertSame('strip', $blocks[2]['display']);
		$this->assertSame('ziek', $blocks[2]['cards'][0]['stripLabel']);
	}//end testGreetingWeekAndKpiStrip()
}//end class
