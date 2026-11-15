<?php

/**
 * MandateParties: a KvK number is exactly eight digits, with nothing after.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
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

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\MandateParties;
use PHPUnit\Framework\TestCase;

/**
 * The KvK party of a mandate.
 */
class MandatePartiesKvkTest extends TestCase {
	/**
	 * An eHerkenning subject is its KvK party, and a trailing newline is no KvK number.
	 *
	 * @return void
	 */
	public function testAKvkPartyIsExactlyEightDigits(): void {
		$parties = new MandateParties();
		$this->assertSame(expected: 'kvk:12345678', actual: $parties->partyOf(subject: ['provider' => 'eherkenning', 'kvk' => '12345678']));
		$this->assertNull(actual: $parties->partyOf(subject: ['provider' => 'eherkenning', 'kvk' => "12345678\n"]));
		$this->assertContains(needle: 'kvk:12345678', haystack: $parties->holdersOf(subject: ['provider' => 'eherkenning', 'kvk' => '12345678']));
		$this->assertNotContains(needle: "kvk:12345678\n", haystack: $parties->holdersOf(subject: ['provider' => 'eherkenning', 'kvk' => "12345678\n"]));
	}//end testAKvkPartyIsExactlyEightDigits()

	/**
	 * A bare value is trimmed, then typed as KvK only when it is exactly eight digits.
	 *
	 * @return void
	 */
	public function testABareValueIsTypedStrictly(): void {
		$parties = new MandateParties();
		$this->assertSame(expected: 'kvk:12345678', actual: $parties->typed(value: '12345678'));
		$this->assertSame(expected: 'kvk:12345678', actual: $parties->typed(value: " 12345678\n"));
		$this->assertSame(expected: 'subject:1234567', actual: $parties->typed(value: '1234567'));
	}//end testABareValueIsTypedStrictly()
}//end class
