<?php

/**
 * A calendar block drawn as a timetable keeps exactly the keys the contract
 * names, through the whole manifest normaliser, and drops what does not fit
 * (calendar-timetable-display).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md
 */
class TimetableKeysTest extends TestCase {

	/**
	 * Run one calendar block through the real manifest normaliser.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>|null The surviving block, or null.
	 */
	private function calendar(array $block): ?array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'lessen', 'schema' => 'session', 'fields' => ['vak', 'begin', 'eind', 'lokaal', 'reden', 'soort', 'status']],
				],
				'pages'       => [
					[
						'id'     => 'rooster',
						'label'  => 'Rooster',
						'blocks' => [$block, ['type' => 'richText', 'markdown' => 'Welkom']],
					],
				],
			]
		);

		foreach ($out['pages'][0]['blocks'] as $kept) {
			if ($kept['type'] === 'calendar') {
				return $kept;
			}
		}

		return null;
	}//end calendar()

	/**
	 * The source of the examples: one lesson collection.
	 *
	 * @param array<string, mixed> $extra Keys added to the source.
	 *
	 * @return array<string, mixed>
	 */
	private function source(array $extra = []): array {
		return array_merge(['collection' => 'lessen', 'startField' => 'begin', 'endField' => 'eind', 'titleField' => 'vak', 'metaField' => 'lokaal'], $extra);
	}//end source()

	public function testATimetableKeepsItsDisplayRangeAndFirstLabel(): void {
		$block = $this->calendar(['type' => 'calendar', 'display' => 'timetable', 'range' => 'week', 'firstLabel' => '  Je eerste les ', 'sources' => [$this->source()]]);

		$this->assertNotNull($block);
		$this->assertSame('timetable', $block['display']);
		$this->assertSame('week', $block['range']);
		$this->assertSame('Je eerste les', $block['firstLabel']);
	}//end testATimetableKeepsItsDisplayRangeAndFirstLabel()

	public function testASourceKeepsItsNoteStatusAndCancelledRule(): void {
		$block = $this->calendar(
			[
				'type'    => 'calendar',
				'display' => 'timetable',
				'sources' => [$this->source(['noteField' => 'reden', 'statusField' => 'soort', 'cancelledWhen' => ['field' => 'status', 'in' => ['cancelled', '', 7]]])],
			]
		);

		$source = $block['sources'][0];
		$this->assertSame('reden', $source['noteField']);
		$this->assertSame('soort', $source['statusField']);
		$this->assertSame(['field' => 'status', 'in' => ['cancelled']], $source['cancelledWhen'], 'only non-empty strings are kept');
	}//end testASourceKeepsItsNoteStatusAndCancelledRule()

	public function testWhatDoesNotFitIsDropped(): void {
		$block = $this->calendar(
			[
				'type'       => 'calendar',
				'display'    => 'agenda',
				'firstLabel' => 'Je eerste les',
				'sources'    => [$this->source(['noteField' => '', 'statusField' => ['soort'], 'cancelledWhen' => ['field' => 'status', 'in' => []]])],
			]
		);

		$this->assertNotNull($block, 'the calendar stays; only the keys that do not fit go');
		$this->assertArrayNotHasKey('display', $block);
		$this->assertArrayNotHasKey('firstLabel', $block, 'a first label belongs to a timetable only');
		$this->assertArrayNotHasKey('noteField', $block['sources'][0]);
		$this->assertArrayNotHasKey('statusField', $block['sources'][0]);
		$this->assertArrayNotHasKey('cancelledWhen', $block['sources'][0]);
	}//end testWhatDoesNotFitIsDropped()

	public function testAFirstLabelThatIsTooLongIsDropped(): void {
		$block = $this->calendar(['type' => 'calendar', 'display' => 'timetable', 'firstLabel' => str_repeat('a', 61), 'sources' => [$this->source()]]);

		$this->assertSame('timetable', $block['display']);
		$this->assertArrayNotHasKey('firstLabel', $block);
	}//end testAFirstLabelThatIsTooLongIsDropped()
}//end class
