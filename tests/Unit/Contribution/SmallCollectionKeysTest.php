<?php

/**
 * ProfilePrefillKey, VisibleFromField and NonEmptyStrings: three small keys
 * of a contribution, each kept only when well formed.
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

use OCA\Portaliq\Contribution\ProfilePrefillKey;
use OCA\Portaliq\Contribution\VisibleFromField;
use OCA\Portaliq\Service\NonEmptyStrings;
use PHPUnit\Framework\TestCase;

/**
 * The small collection keys.
 */
class SmallCollectionKeysTest extends TestCase {
	/**
	 * A field may be prefilled from the profile's name or email, nothing else.
	 *
	 * @return void
	 */
	public function testPrefillNamesAProfileField(): void {
		$key = new ProfilePrefillKey();
		$this->assertSame(expected: ['key' => 'k', 'prefill' => 'name'], actual: $key->apply(entry: ['key' => 'k'], source: ['prefill' => 'name']));
		$this->assertSame(expected: ['key' => 'k', 'prefill' => 'email'], actual: $key->apply(entry: ['key' => 'k'], source: ['prefill' => 'email']));
		foreach (['bsn', 'Name', '', true, ['name']] as $declared) {
			$this->assertSame(expected: ['key' => 'k'], actual: $key->apply(entry: ['key' => 'k'], source: ['prefill' => $declared]));
		}
	}//end testPrefillNamesAProfileField()

	/**
	 * A visibleFrom field that is a name is kept and added to the projected fields.
	 *
	 * @return void
	 */
	public function testAVisibleFromFieldIsProjected(): void {
		$field = new VisibleFromField();
		$this->assertSame(
			expected: ['fields' => ['title', 'publishAt'], 'visibleFromField' => 'publishAt'],
			actual: $field->normalise(collection: ['fields' => ['title'], 'visibleFromField' => 'publishAt'])
		);
		$this->assertSame(
			expected: ['fields' => ['publishAt'], 'visibleFromField' => 'publishAt'],
			actual: $field->normalise(collection: ['fields' => ['publishAt'], 'visibleFromField' => 'publishAt'])
		);
		$this->assertSame(expected: ['visibleFromField' => 'publish_at2'], actual: $field->normalise(collection: ['visibleFromField' => 'publish_at2']));
	}//end testAVisibleFromFieldIsProjected()

	/**
	 * A visibleFrom field that is not a name is removed, the fields left alone.
	 *
	 * @return void
	 */
	public function testAMalformedVisibleFromFieldIsRemoved(): void {
		$field = new VisibleFromField();
		foreach (['2publish', 'publish-at', 'a.b', '', null, ['publishAt']] as $declared) {
			$normalised = $field->normalise(collection: ['fields' => ['title'], 'visibleFromField' => $declared]);
			$this->assertSame(expected: ['fields' => ['title']], actual: $normalised);
		}

		$this->assertSame(expected: ['fields' => ['title']], actual: $field->normalise(collection: ['fields' => ['title']]));
	}//end testAMalformedVisibleFromFieldIsRemoved()

	/**
	 * Only non-empty strings are kept from a list, in order and re-indexed.
	 *
	 * @return void
	 */
	public function testOnlyNonEmptyStringsAreKept(): void {
		$strings = new NonEmptyStrings();
		$this->assertSame(expected: ['a', ' ', 'b'], actual: $strings->from(value: ['x' => 'a', '', ' ', null, 3, ['c'], 'b']));
		$this->assertSame(expected: [], actual: $strings->from(value: 'a'));
		$this->assertSame(expected: [], actual: $strings->from(value: null));
	}//end testOnlyNonEmptyStringsAreKept()
}//end class
