<?php

/**
 * ContactAddressChange: the account fields a sign-in address change writes,
 * when it is asked for, confirmed and read back.
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

use OCA\Portaliq\Service\Identity\ContactAddressChange;
use PHPUnit\Framework\TestCase;

/**
 * The contact address change.
 */
class ContactAddressChangeTest extends TestCase {
	/**
	 * An account with a confirmed, preferred address.
	 */
	private const ACCOUNT = [
		'email'            => 'old@example.org',
		'contactAddresses' => [['kind' => 'email', 'value' => 'old@example.org', 'confirmed' => true, 'preferred' => true]],
	];

	/**
	 * A new address is added unconfirmed and waits for its token.
	 *
	 * @return void
	 */
	public function testANewAddressWaitsForConfirmation(): void {
		$fields = (new ContactAddressChange())->changedFields(account: self::ACCOUNT, email: ' New@Example.org ', token: 'tok');
		$this->assertSame(
			expected: [
				['kind' => 'email', 'value' => 'old@example.org', 'confirmed' => true, 'preferred' => true],
				['kind' => 'email', 'value' => $fields['pendingEmail'], 'confirmed' => false, 'preferred' => false],
			],
			actual: $fields['contactAddresses']
		);
		$this->assertSame(expected: 'New@Example.org', actual: $fields['pendingEmail']);
		$this->assertSame(expected: hash('sha256', 'tok'), actual: $fields['pendingEmailTokenHash']);
		$this->assertSame(expected: 'replace', actual: $fields['pendingEmailMode']);
		$this->assertGreaterThan(expected: time(), actual: strtotime($fields['pendingEmailExpiresAt']));
		$this->assertArrayNotHasKey(key: 'email', array: $fields);
	}//end testANewAddressWaitsForConfirmation()

	/**
	 * An address that is not an address changes nothing.
	 *
	 * @return void
	 */
	public function testAnInvalidAddressChangesNothing(): void {
		$change = new ContactAddressChange();
		$this->assertNull(actual: $change->changedFields(account: self::ACCOUNT, email: 'not an address', token: 'tok'));
		$this->assertNull(actual: $change->changedFields(account: self::ACCOUNT, email: '', token: 'tok'));
	}//end testAnInvalidAddressChangesNothing()

	/**
	 * An address already confirmed on the account becomes the one in use, with no token.
	 *
	 * @return void
	 */
	public function testAKnownConfirmedAddressIsPreferredAtOnce(): void {
		$account = self::ACCOUNT;
		$account['contactAddresses'][] = ['kind' => 'email', 'value' => 'work@example.org', 'confirmed' => true, 'preferred' => false];
		$fields  = (new ContactAddressChange())->changedFields(account: $account, email: 'work@example.org', token: 'tok');
		$this->assertSame(expected: 'work@example.org', actual: $fields['email']);
		$this->assertSame(expected: [false, true], actual: array_column($fields['contactAddresses'], 'preferred'));
		$this->assertArrayNotHasKey(key: 'pendingEmailTokenHash', array: $fields);
	}//end testAKnownConfirmedAddressIsPreferredAtOnce()

	/**
	 * Too many unconfirmed addresses refuse another one.
	 *
	 * @return void
	 */
	public function testTooManyPendingAddressesRefuseAnother(): void {
		$account = self::ACCOUNT;
		foreach (range(1, 5) as $n) {
			$account['contactAddresses'][] = ['kind' => 'email', 'value' => 'p'.$n.'@example.org', 'confirmed' => false, 'preferred' => false];
		}

		$this->assertNull(actual: (new ContactAddressChange())->changedFields(account: $account, email: 'six@example.org', token: 'tok'));
	}//end testTooManyPendingAddressesRefuseAnother()

	/**
	 * Confirming replaces the address in use and clears the pending change.
	 *
	 * @return void
	 */
	public function testConfirmingReplacesTheAddressInUse(): void {
		$account = self::ACCOUNT + ['pendingEmail' => 'new@example.org', 'pendingEmailMode' => 'replace'];
		$fields  = (new ContactAddressChange())->confirmedFields(account: $account);
		$this->assertSame(expected: 'new@example.org', actual: $fields['email']);
		$this->assertTrue(condition: $fields['verifiedEmail']);
		$this->assertSame(expected: '', actual: $fields['pendingEmail']);
		$this->assertSame(expected: '', actual: $fields['pendingEmailTokenHash']);
		$this->assertSame(
			expected: [
				['kind' => 'email', 'value' => 'old@example.org', 'confirmed' => true, 'preferred' => false],
				['kind' => 'email', 'value' => 'new@example.org', 'confirmed' => true, 'preferred' => true],
			],
			actual: $fields['contactAddresses']
		);
	}//end testConfirmingReplacesTheAddressInUse()

	/**
	 * Confirming an added address keeps the one in use and verifies nothing new.
	 *
	 * @return void
	 */
	public function testConfirmingAnAddedAddressKeepsTheOneInUse(): void {
		$account = self::ACCOUNT + ['pendingEmail' => 'extra@example.org', 'pendingEmailMode' => 'add'];
		$fields  = (new ContactAddressChange())->confirmedFields(account: $account);
		$this->assertSame(expected: 'old@example.org', actual: $fields['email']);
		$this->assertArrayNotHasKey(key: 'verifiedEmail', array: $fields);
		$this->assertSame(expected: [true, true], actual: array_column($fields['contactAddresses'], 'confirmed'));
	}//end testConfirmingAnAddedAddressKeepsTheOneInUse()

	/**
	 * Reading the fields masks the pending address and defaults the channel to the portal.
	 *
	 * @return void
	 */
	public function testReadingMasksThePendingAddress(): void {
		$change = new ContactAddressChange();
		$read   = $change->readFields(account: self::ACCOUNT + ['pendingEmail' => 'new@example.org']);
		$this->assertSame(expected: 'n***@example.org', actual: $read['pendingEmail']);
		$this->assertSame(expected: 'portal', actual: $read['contactChannel']);
		$this->assertCount(expectedCount: 1, haystack: $read['contactAddresses']);
		$this->assertSame(expected: 'mail', actual: $change->readFields(account: ['contactChannel' => 'mail'])['contactChannel']);
		$this->assertSame(expected: '', actual: $change->readFields(account: [])['pendingEmail']);
	}//end testReadingMasksThePendingAddress()
}//end class
