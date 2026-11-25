<?php

/**
 * DisplayKeys: the field, text, list, number and tone keys of each display.
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

use OCA\Portaliq\Contribution\DisplayKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The display keys, display by display.
 */
class DisplayKeysTest extends TestCase {
	/**
	 * The fields the collection projects.
	 */
	private const FIELDS = ['title', 'status', 'note', 'date', 'value', 'label', 'values', 'avg', 'who'];

	/**
	 * The keys of a block against a collection projecting the fields above.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 */
	private function keys(array $block): array {
		return (new DisplayKeys())->keys(block: $block, collection: ['fields' => self::FIELDS]);
	}//end keys()

	/**
	 * A display that is not one of the four yields no keys at all.
	 *
	 * @return void
	 */
	public function testAnUnknownDisplayYieldsNothing(): void {
		foreach ([null, 'table', 'Cards', 3, ['cards']] as $display) {
			$this->assertSame(expected: [], actual: $this->keys(block: ['display' => $display, 'statusField' => 'status']));
		}
	}//end testAnUnknownDisplayYieldsNothing()

	/**
	 * Each display and the field keys it owns.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function displayFields(): array {
		return [
			'cards' => ['cards', ['statusField' => 'status', 'noteField' => 'note', 'soonField' => 'date']],
			'rows'  => ['rows', ['dateField' => 'date', 'subtitleField' => 'who', 'quoteField' => 'note', 'statusField' => 'status']],
			'bars'  => ['bars', ['labelField' => 'label', 'valueField' => 'value', 'noteField' => 'note', 'captionField' => 'title']],
			'chips' => ['chips', ['labelField' => 'label', 'valuesField' => 'values', 'averageField' => 'avg']],
		];
	}//end displayFields()

	/**
	 * A display keeps its own projected field keys and ignores the others' keys.
	 *
	 * @param string $display The display.
	 * @param array<string, string> $fields Its field keys.
	 *
	 * @return void
	 */
	#[DataProvider('displayFields')]
	public function testADisplayKeepsOnlyItsOwnFieldKeys(string $display, array $fields): void {
		$foreign = ['statusField' => 'status', 'labelField' => 'label', 'dateField' => 'date', 'averageField' => 'avg'];
		$keys    = $this->keys(block: ['display' => $display] + $fields + $foreign);
		$this->assertSame(expected: 'display', actual: array_key_first($keys));
		foreach ($fields as $key => $field) {
			$this->assertSame(expected: $field, actual: $keys[$key]);
		}

		foreach (array_keys(array_diff_key($foreign, $fields)) as $key) {
			$this->assertArrayNotHasKey(key: $key, array: $keys, message: $display.' '.$key);
		}
	}//end testADisplayKeepsOnlyItsOwnFieldKeys()

	/**
	 * A field the collection does not project is dropped.
	 *
	 * @return void
	 */
	public function testAnUnprojectedFieldIsDropped(): void {
		$this->assertSame(expected: ['display' => 'rows'], actual: $this->keys(block: ['display' => 'rows', 'dateField' => 'bsn', 'quoteField' => '']));
		$keys = (new DisplayKeys())->keys(block: ['display' => 'rows', 'dateField' => 'date'], collection: null);
		$this->assertSame(expected: ['display' => 'rows'], actual: $keys);
	}//end testAnUnprojectedFieldIsDropped()

	/**
	 * The text keys are trimmed and at most 80 characters.
	 *
	 * @return void
	 */
	public function testTextKeysAreTrimmedAndBounded(): void {
		$this->assertSame(expected: 'Soon', actual: $this->keys(block: ['display' => 'cards', 'soonLabel' => ' Soon '])['soonLabel']);
		$this->assertSame(expected: 'Average', actual: $this->keys(block: ['display' => 'bars', 'noteLabel' => 'Average'])['noteLabel']);
		$this->assertArrayNotHasKey(key: 'soonLabel', array: $this->keys(block: ['display' => 'cards', 'soonLabel' => str_repeat('x', 81)]));
		$this->assertArrayNotHasKey(key: 'soonLabel', array: $this->keys(block: ['display' => 'cards', 'soonLabel' => '  ']));
		$this->assertArrayNotHasKey(key: 'soonLabel', array: $this->keys(block: ['display' => 'rows', 'soonLabel' => 'Soon']));
	}//end testTextKeysAreTrimmedAndBounded()

	/**
	 * Rows keep title fields, cards keep subtitle fields and an avatar.
	 *
	 * @return void
	 */
	public function testTheListKeysBelongToRowsAndCards(): void {
		$this->assertSame(
			expected: ['display' => 'rows', 'titleFields' => ['title', 'who']],
			actual: $this->keys(block: ['display' => 'rows', 'titleFields' => ['title', 'bsn', 'who'], 'subtitleFields' => ['who'], 'avatar' => true])
		);
		$this->assertSame(
			expected: ['display' => 'cards', 'subtitleFields' => ['who'], 'avatar' => true],
			actual: $this->keys(block: ['display' => 'cards', 'titleFields' => ['title'], 'subtitleFields' => ['who', 'bsn'], 'avatar' => true])
		);
		$this->assertArrayNotHasKey(key: 'avatar', array: $this->keys(block: ['display' => 'cards', 'avatar' => 'true']));
		$this->assertArrayNotHasKey(key: 'titleFields', array: $this->keys(block: ['display' => 'rows', 'titleFields' => ['bsn']]));
	}//end testTheListKeysBelongToRowsAndCards()

	/**
	 * Numbers and whether they are kept.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function numbers(): array {
		return [
			'int'      => [10, true],
			'float'    => [2.5, true],
			'maximum'  => [1000, true],
			'too big'  => [1001, false],
			'zero'     => [0, false],
			'negative' => [-3, false],
			'string'   => ['10', false],
		];
	}//end numbers()

	/**
	 * Bars take a max, chips a lowBelow, each a positive number up to 1000.
	 *
	 * @param mixed $value The declared number.
	 * @param bool $kept Whether it survives.
	 *
	 * @return void
	 */
	#[DataProvider('numbers')]
	public function testTheNumberKeysArePositiveAndBounded(mixed $value, bool $kept): void {
		$this->assertSame(expected: $kept, actual: array_key_exists('max', $this->keys(block: ['display' => 'bars', 'max' => $value])));
		$chips = $this->keys(block: ['display' => 'chips', 'lowBelow' => $value]);
		$this->assertSame(expected: $kept, actual: array_key_exists('lowBelow', $chips));
		$this->assertArrayNotHasKey(key: 'max', array: $this->keys(block: ['display' => 'chips', 'max' => $value]));
		$this->assertArrayNotHasKey(key: 'lowBelow', array: $this->keys(block: ['display' => 'rows', 'lowBelow' => $value]));
	}//end testTheNumberKeysArePositiveAndBounded()

	/**
	 * Status tones keep the known tones on rows and cards only.
	 *
	 * @return void
	 */
	public function testStatusTonesKeepTheKnownTones(): void {
		$tones = ['open' => 'warning', 'done' => 'success', 'lost' => 'red', '' => 'error', 'x' => null, 'closed' => 'neutral'];
		$this->assertSame(
			expected: ['open' => 'warning', 'done' => 'success', 'closed' => 'neutral'],
			actual: $this->keys(block: ['display' => 'rows', 'statusTones' => $tones])['statusTones']
		);
		$cards = $this->keys(block: ['display' => 'cards', 'statusTones' => ['open' => 'warning']]);
		$this->assertSame(expected: ['open' => 'warning'], actual: $cards['statusTones']);
		$this->assertArrayNotHasKey(key: 'statusTones', array: $this->keys(block: ['display' => 'bars', 'statusTones' => ['open' => 'warning']]));
		$this->assertArrayNotHasKey(key: 'statusTones', array: $this->keys(block: ['display' => 'rows', 'statusTones' => ['open' => 'purple']]));
		$this->assertArrayNotHasKey(key: 'statusTones', array: $this->keys(block: ['display' => 'rows', 'statusTones' => 'warning']));
	}//end testStatusTonesKeepTheKnownTones()

	/**
	 * At most twenty tones are kept, keeping their keys.
	 *
	 * @return void
	 */
	public function testStatusTonesAreCappedAtTwenty(): void {
		$tones = [];
		foreach (range(1, 25) as $n) {
			$tones['s'.$n] = 'neutral';
		}

		$kept = $this->keys(block: ['display' => 'rows', 'statusTones' => $tones])['statusTones'];
		$this->assertCount(expectedCount: 20, haystack: $kept);
		$this->assertSame(expected: 's20', actual: array_key_last($kept));
	}//end testStatusTonesAreCappedAtTwenty()
	/**
	 * Rows keep a note per status value: its projected name field, its
	 * moment and its template; an entry without a projected field is dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rows-status-note-per-state/specs/portal-contribution-contract/spec.md#requirement-a-dated-row-may-say-a-different-note-per-status
	 */
	public function testRowsKeepANotePerStatus(): void {
		$notes = [
			'submitted' => [
				'field'    => 'who',
				'atField'  => 'date',
				'render'   => 'user',
				'template' => '  Gezien door {name} om {time} uur ',
			],
			'approved'  => ['field' => 'who', 'atField' => 'secret', 'template' => str_repeat('x', 81)],
			'rejected'  => ['field' => 'secret'],
			''          => ['field' => 'who'],
			'odd'       => 'who',
		];
		$this->assertSame(
			expected: [
				'submitted' => ['field' => 'who', 'atField' => 'date', 'template' => 'Gezien door {name} om {time} uur'],
				'approved'  => ['field' => 'who'],
			],
			actual: $this->keys(block: ['display' => 'rows', 'statusNotes' => $notes])['statusNotes']
		);
		$this->assertArrayNotHasKey(key: 'statusNotes', array: $this->keys(block: ['display' => 'cards', 'statusNotes' => $notes]));
		$unprojected = $this->keys(block: ['display' => 'rows', 'statusNotes' => ['rejected' => ['field' => 'secret']]]);
		$this->assertArrayNotHasKey(key: 'statusNotes', array: $unprojected);
		$this->assertArrayNotHasKey(key: 'statusNotes', array: $this->keys(block: ['display' => 'rows', 'statusNotes' => 'who']));
	}//end testRowsKeepANotePerStatus()

	/**
	 * At most twenty notes are kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rows-status-note-per-state/specs/portal-contribution-contract/spec.md#requirement-a-dated-row-may-say-a-different-note-per-status
	 */
	public function testStatusNotesAreCappedAtTwenty(): void {
		$notes = [];
		foreach (range(1, 25) as $n) {
			$notes['s'.$n] = ['field' => 'who'];
		}

		$this->assertCount(expectedCount: 20, haystack: $this->keys(block: ['display' => 'rows', 'statusNotes' => $notes])['statusNotes']);
	}//end testStatusNotesAreCappedAtTwenty()
}//end class
