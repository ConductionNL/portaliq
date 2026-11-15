<?php

/**
 * SchoolBlockKeys: the highlight tasks display, the segmented kpi bar, the
 * calendar tiles and the greeting block, called directly.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\SchoolBlockKeys;
use PHPUnit\Framework\TestCase;

/**
 * The school block keys.
 */
class SchoolBlockKeysDirectTest extends TestCase {
	/**
	 * The highlight display keeps its texts and the projected subtitle fields.
	 *
	 * @return void
	 */
	public function testTheHighlightDisplayKeepsTextsAndFields(): void {
		$keys = (new SchoolBlockKeys())->tasksKeys(
			block: [
				'display'        => 'highlight',
				'eyebrow'        => ' Vandaag ',
				'buttonLabel'    => str_repeat('b', 61),
				'subtitleFields' => ['subject', 'secret', '', 4],
				'dueInLine'      => true,
			],
			collection: ['fields' => ['subject', 'due']]
		);
		$this->assertSame(
			expected: ['display' => 'highlight', 'eyebrow' => 'Vandaag', 'subtitleFields' => ['subject'], 'dueInLine' => true],
			actual: $keys
		);
	}//end testTheHighlightDisplayKeepsTextsAndFields()

	/**
	 * Any other display yields no tasks keys at all.
	 *
	 * @return void
	 */
	public function testOtherDisplaysYieldNoTasksKeys(): void {
		$keys = new SchoolBlockKeys();
		foreach ([[], ['display' => 'list', 'eyebrow' => 'x'], ['display' => 'Highlight']] as $block) {
			$this->assertSame(expected: [], actual: $keys->tasksKeys(block: $block, collection: []));
		}
	}//end testOtherDisplaysYieldNoTasksKeys()

	/**
	 * A collection that names no fields lets any subtitle field through.
	 *
	 * @return void
	 */
	public function testACollectionWithoutFieldsAcceptsAnySubtitle(): void {
		$keys = (new SchoolBlockKeys())->tasksKeys(block: ['display' => 'highlight', 'subtitleFields' => ['any']], collection: []);
		$this->assertSame(expected: ['any'], actual: $keys['subtitleFields']);
	}//end testACollectionWithoutFieldsAcceptsAnySubtitle()

	/**
	 * A segmented bar keeps up to five well-formed segments, defaulting their tone.
	 *
	 * @return void
	 */
	public function testASegmentedBarKeepsItsSegments(): void {
		$keys = (new SchoolBlockKeys())->segmentedKeys(
			block: [
				'display'    => 'segmented',
				'segments'   => [
					['field' => 'present', 'label' => ' Aanwezig ', 'tone' => 'positive'],
					['field' => 'late', 'label' => 'Te laat', 'tone' => 'warning'],
					['field' => 'excused', 'label' => 'Afgemeld', 'tone' => 'red'],
					['field' => 'absent'],
					['label' => 'No field'],
					'junk',
				],
				'totalField' => 'lessons',
				'target'     => 0.95,
				'unit'       => ' lessen ',
			]
		);
		$this->assertSame(
			expected: [
				'display'    => 'segmented',
				'segments'   => [
					['field' => 'present', 'label' => 'Aanwezig', 'tone' => 'positive'],
					['field' => 'late', 'label' => 'Te laat', 'tone' => 'warning'],
					['field' => 'excused', 'label' => 'Afgemeld', 'tone' => 'positive'],
				],
				'totalField' => 'lessons',
				'target'     => 0.95,
				'unit'       => 'lessen',
			],
			actual: $keys
		);
	}//end testASegmentedBarKeepsItsSegments()

	/**
	 * Without a surviving segment there is no segmented bar.
	 *
	 * @return void
	 */
	public function testABarWithoutSegmentsIsNoBar(): void {
		$keys = new SchoolBlockKeys();
		$this->assertSame(expected: [], actual: $keys->segmentedKeys(block: ['display' => 'segmented', 'segments' => [['field' => ' ', 'label' => 'x']]]));
		$this->assertSame(expected: [], actual: $keys->segmentedKeys(block: ['display' => 'segmented']));
		$this->assertSame(expected: [], actual: $keys->segmentedKeys(block: ['segments' => [['field' => 'a', 'label' => 'A']]]));
	}//end testABarWithoutSegmentsIsNoBar()

	/**
	 * At most five segments are kept; a target must be a positive number.
	 *
	 * @return void
	 */
	public function testSegmentsAreCappedAndTheTargetIsPositive(): void {
		$segments = array_map(static fn (int $n): array => ['field' => 'f'.$n, 'label' => 'L'.$n, 'tone' => 'waiting'], range(1, 8));
		$keys     = new SchoolBlockKeys();
		$bar      = $keys->segmentedKeys(block: ['display' => 'segmented', 'segments' => $segments, 'target' => 0]);
		$this->assertCount(expectedCount: 5, haystack: $bar['segments']);
		$this->assertSame(expected: 'f5', actual: $bar['segments'][4]['field']);
		$this->assertArrayNotHasKey(key: 'target', array: $bar);
		$block = ['display' => 'segmented', 'segments' => $segments];
		$this->assertArrayNotHasKey(key: 'target', array: $keys->segmentedKeys(block: $block + ['target' => '10']));
		$this->assertSame(expected: 10, actual: $keys->segmentedKeys(block: $block + ['target' => 10])['target']);
	}//end testSegmentsAreCappedAndTheTargetIsPositive()

	/**
	 * The calendar only knows the tiles display.
	 *
	 * @return void
	 */
	public function testTheCalendarOnlyKnowsTiles(): void {
		$keys = new SchoolBlockKeys();
		$this->assertSame(expected: ['display' => 'tiles'], actual: $keys->calendarKeys(block: ['display' => 'tiles']));
		$this->assertSame(expected: [], actual: $keys->calendarKeys(block: ['display' => 'month']));
		$this->assertSame(expected: [], actual: $keys->calendarKeys(block: []));
	}//end testTheCalendarOnlyKnowsTiles()

	/**
	 * A greeting shows the date unless told not to, and may carry one call to action.
	 *
	 * @return void
	 */
	public function testTheGreetingBlock(): void {
		$keys = new SchoolBlockKeys();
		$this->assertSame(expected: ['type' => 'greeting', 'showDate' => true], actual: $keys->greetingBlock(block: [], actionIds: [], pageIds: []));
		$this->assertSame(
			expected: ['type' => 'greeting', 'showDate' => true],
			actual: $keys->greetingBlock(block: ['showDate' => 'false'], actionIds: [], pageIds: [])
		);
		$this->assertSame(
			expected: ['type' => 'greeting', 'showDate' => false, 'page' => 'rooster', 'label' => 'Rooster'],
			actual: $keys->greetingBlock(
				block: ['showDate' => false, 'page' => 'rooster', 'label' => 'Rooster', 'withRecord' => true],
				actionIds: [],
				pageIds: ['rooster']
			)
		);
		$this->assertSame(
			expected: ['type' => 'greeting', 'showDate' => true],
			actual: $keys->greetingBlock(block: ['page' => 'admin', 'label' => 'Beheer'], actionIds: [], pageIds: ['rooster'])
		);
	}//end testTheGreetingBlock()
}//end class
