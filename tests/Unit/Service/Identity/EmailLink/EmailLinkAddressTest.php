<?php

/**
 * Unit tests for how an e-mail link address is compared, hashed and masked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use PHPUnit\Framework\TestCase;

/**
 * One address, one spelling: lower case, trimmed, hashed and masked.
 */
class EmailLinkAddressTest extends TestCase {
	/**
	 * @return void
	 */
	public function testNormaliseTrimsAndLowerCases(): void {
		$this->assertSame('tom@example.org', (new EmailLinkAddress())->normalise(address: "  Tom@Example.ORG \n"));
	}//end testNormaliseTrimsAndLowerCases()

	/**
	 * @return void
	 */
	public function testTheHashIgnoresSpellingAndAnEmptyAddressHasNone(): void {
		$address = new EmailLinkAddress();

		$this->assertSame(hash('sha256', 'tom@example.org'), $address->hash(address: ' Tom@Example.org'));
		$this->assertSame('', $address->hash(address: '   '));
	}//end testTheHashIgnoresSpellingAndAnEmptyAddressHasNone()

	/**
	 * @return void
	 */
	public function testTheMaskKeepsTheFirstLetterAndTheDomainOnly(): void {
		$address = new EmailLinkAddress();

		$this->assertSame('t***@example.org', $address->mask(address: 'Tom@Example.org'));
		$this->assertSame('***', $address->mask(address: 'no-at-sign'));
		$this->assertSame('***', $address->mask(address: '@example.org'));
	}//end testTheMaskKeepsTheFirstLetterAndTheDomainOnly()
}//end class
