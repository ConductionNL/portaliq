<?php

/**
 * Unit tests for CalendarSourceNormaliser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CalendarSourceNormaliser;
use OCA\Portaliq\Contribution\RecordScopeNormaliser;
use OCA\Portaliq\Contribution\TimetableKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A calendar block's source: which collection, and which of its fields date and title an entry.
 */
#[CoversClass(CalendarSourceNormaliser::class)]
#[UsesClass(TimetableKeys::class)]
#[UsesClass(RecordScopeNormaliser::class)]
class CalendarSourceNormaliserTest extends TestCase {

	/**
	 * The normaliser under test.
	 *
	 * @var CalendarSourceNormaliser
	 */
	private CalendarSourceNormaliser $sources;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sources = new CalendarSourceNormaliser();
	}//end setUp()


	/**
	 * A source on a known collection with a start and a title keeps its sound keys only.
	 *
	 * @return void
	 */
	public function testASoundSourceKeepsItsNamedKeys(): void {
		$this->assertSame(
			[
				'collection'  => 'lessons',
				'startField'  => 'start',
				'titleField'  => 'subject',
				'endField'    => 'end',
				'kind'        => 'lesson',
				'noteField'   => 'note',
				'only'        => ['field' => 'group', 'in' => ['3a', '3b']],
				'recordField' => 'pupil',
			],
			$this->sources->source(
				[
					'collection'  => 'lessons',
					'startField'  => 'start',
					'titleField'  => 'subject',
					'endField'    => 'end',
					'metaField'   => '',
					'kind'        => 'lesson',
					'noteField'   => 'note',
					'only'        => ['field' => 'group', 'in' => ['3a', '', 7, '3b']],
					'recordField' => 'pupil',
					'other'       => 'dropped',
				],
				['lessons']
			)
		);
	}//end testASoundSourceKeepsItsNamedKeys()


	/**
	 * A source needs a known collection, a start field and a title or title field.
	 *
	 * @return void
	 */
	public function testASourceWithoutItsEssentialsIsDropped(): void {
		$this->assertNull($this->sources->source('lessons', ['lessons']));
		$this->assertNull($this->sources->source(['collection' => 'other', 'startField' => 's', 'title' => 't'], ['lessons']));
		$this->assertNull($this->sources->source(['collection' => 'lessons', 'title' => 't'], ['lessons']));
		$this->assertNull($this->sources->source(['collection' => 'lessons', 'startField' => 's'], ['lessons']));
		$this->assertSame(
			['collection' => 'lessons', 'startField' => 's', 'title' => 't'],
			$this->sources->source(['collection' => 'lessons', 'startField' => 's', 'title' => 't'], ['lessons'])
		);
	}//end testASourceWithoutItsEssentialsIsDropped()


	/**
	 * An expanded source takes its dates from the expanded field's rows, and needs that field.
	 *
	 * @return void
	 */
	public function testAnExpandedSourceDatesItsRows(): void {
		$this->assertSame(
			['collection' => 'courses', 'expand' => ['field' => 'sessions', 'startField' => 'at', 'title' => 'name']],
			$this->sources->source(['collection' => 'courses', 'expand' => ['field' => 'sessions', 'startField' => 'at', 'title' => 'name']], ['courses'])
		);
		$this->assertNull($this->sources->source(['collection' => 'courses', 'expand' => ['startField' => 'at', 'title' => 'name']], ['courses']));
		$this->assertNull($this->sources->source(['collection' => 'courses', 'startField' => 'at', 'title' => 'name', 'expand' => ['field' => 'sessions']], ['courses']));
	}//end testAnExpandedSourceDatesItsRows()


	/**
	 * A value filter without a field or without usable values is left out.
	 *
	 * @return void
	 */
	public function testABadValueFilterIsLeftOut(): void {
		$base = ['collection' => 'lessons', 'startField' => 's', 'title' => 't'];

		$this->assertArrayNotHasKey('only', $this->sources->source($base + ['only' => ['in' => ['a']]], ['lessons']));
		$this->assertArrayNotHasKey('only', $this->sources->source($base + ['only' => ['field' => 'g', 'in' => ['', 1]]], ['lessons']));
		$this->assertArrayNotHasKey('kind', $this->sources->source($base + ['kind' => ''], ['lessons']));
	}//end testABadValueFilterIsLeftOut()
}//end class
