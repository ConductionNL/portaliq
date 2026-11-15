<?php

/**
 * Tests for how the portal shows a case field's value: personal data and format.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CaseFieldDisplayHints;
use PHPUnit\Framework\TestCase;

/**
 * Declared hints are kept only when they say something the site uses, and
 * laid over field states without touching what makes a field writable.
 *
 * @covers \OCA\Portaliq\Contribution\CaseFieldDisplayHints
 *
 * @spec openspec/changes/personal-data-left-untranslated/specs/site-chrome/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
 */
class CaseFieldDisplayHintsTest extends TestCase {
	/**
	 * A map keyed by field keeps `personal: true` and a known format, lower-cased.
	 */
	public function testAMapKeepsPersonalAndKnownFormats(): void {
		$this->assertSame(
			[
				'kenteken' => ['personal' => true],
				'email' => ['format' => 'email'],
				'telefoon' => ['personal' => true, 'format' => 'telephone'],
			],
			CaseFieldDisplayHints::normalise(declared: [
				'kenteken' => ['personal' => true],
				'email' => ['format' => 'Email'],
				'telefoon' => ['personal' => true, 'format' => 'telephone'],
			])
		);
	}//end testAMapKeepsPersonalAndKnownFormats()

	/**
	 * A list of entries names each field in `field`.
	 */
	public function testAListNamesItsFields(): void {
		$this->assertSame(
			['kenteken' => ['personal' => true], 'site' => ['format' => 'uri']],
			CaseFieldDisplayHints::normalise(declared: [
				['field' => 'kenteken', 'personal' => true],
				['field' => 'site', 'format' => 'uri'],
				['personal' => true],
				['field' => '', 'personal' => true],
			])
		);
	}//end testAListNamesItsFields()

	/**
	 * Anything short of exactly true, an unknown format, or a malformed entry says nothing.
	 */
	public function testWhatSaysNothingIsDropped(): void {
		$this->assertSame(
			[],
			CaseFieldDisplayHints::normalise(declared: [
				'a' => ['personal' => 'yes'],
				'b' => ['personal' => 1],
				'c' => ['format' => 'date'],
				'd' => ['format' => ['email']],
				'e' => 'personal',
				'f' => [],
			])
		);
		$this->assertSame([], CaseFieldDisplayHints::normalise(declared: null));
		$this->assertSame([], CaseFieldDisplayHints::normalise(declared: 'kenteken'));
	}//end testWhatSaysNothingIsDropped()

	/**
	 * Hints are added to the states of the fields they name; nothing else changes.
	 */
	public function testHintsAreLaidOverTheirFieldsOnly(): void {
		$fields = [
			'kenteken' => ['writable' => false, 'reason' => 'Vast.'],
			'omschrijving' => ['writable' => true, 'reason' => ''],
		];

		$this->assertSame(
			[
				'kenteken' => ['writable' => false, 'reason' => 'Vast.', 'personal' => true],
				'omschrijving' => ['writable' => true, 'reason' => ''],
			],
			CaseFieldDisplayHints::apply(fields: $fields, hints: ['kenteken' => ['personal' => true], 'elders' => ['format' => 'email']])
		);
	}//end testHintsAreLaidOverTheirFieldsOnly()

	/**
	 * A hint cannot change whether a field is writable or why it is closed.
	 */
	public function testAHintCannotOpenAField(): void {
		$fields = ['kenteken' => ['writable' => false, 'reason' => 'Vast.']];

		$this->assertSame(
			['kenteken' => ['writable' => false, 'reason' => 'Vast.']],
			CaseFieldDisplayHints::apply(fields: $fields, hints: ['kenteken' => ['writable' => true, 'reason' => '']])
		);
	}//end testAHintCannotOpenAField()

	/**
	 * Without hints the states come back unchanged.
	 */
	public function testWithoutHintsNothingChanges(): void {
		$fields = ['kenteken' => ['writable' => true, 'reason' => '']];

		$this->assertSame($fields, CaseFieldDisplayHints::apply(fields: $fields, hints: null));
		$this->assertSame($fields, CaseFieldDisplayHints::apply(fields: $fields, hints: []));
		$this->assertSame($fields, CaseFieldDisplayHints::apply(fields: $fields, hints: ['kenteken' => 'personal']));
	}//end testWithoutHintsNothingChanges()
}//end class
