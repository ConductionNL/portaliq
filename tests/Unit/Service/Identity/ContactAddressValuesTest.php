<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ContactAddressValues;
use PHPUnit\Framework\TestCase;

/**
 * identity-profile-page: an alternative contact address is normalised before
 * it is stored — an email is validated, a phone number is written in E.164
 * where it parses — and a masked form is shown back without revealing the
 * whole address.
 *
 * @covers \OCA\Portaliq\Service\Identity\ContactAddressValues
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
 */
class ContactAddressValuesTest extends TestCase {

	private ContactAddressValues $values;

	protected function setUp(): void {
		$this->values = new ContactAddressValues();

	}//end setUp()

	public function testAnEmailIsKeptWhenValidAndRefusedWhenNot(): void {
		$this->assertSame('ann@example.org', $this->values->normalise('email', '  ann@example.org '));
		$this->assertNull($this->values->normalise('email', 'not-an-email'));

	}//end testAnEmailIsKeptWhenValidAndRefusedWhenNot()

	public function testADutchNumberBecomesE164(): void {
		$this->assertSame('+31612345678', $this->values->normalise('phone', '06 12345678'));
		// A leading 00 international prefix becomes +.
		$this->assertSame('+49301234567', $this->values->normalise('phone', '0049 30 1234567'));
		// An already-plus number is kept.
		$this->assertSame('+32456789012', $this->values->normalise('phone', '+32 456 789 012'));

	}//end testADutchNumberBecomesE164()

	public function testAPhoneIsRefusedWhenItIsNotAPhoneAtAllOrTooShort(): void {
		$this->assertNull($this->values->normalise('phone', 'call me'));
		$this->assertNull($this->values->normalise('phone', '12345'));
		// An unknown kind is refused outright.
		$this->assertNull($this->values->normalise('post', 'anything'));

	}//end testAPhoneIsRefusedWhenItIsNotAPhoneAtAllOrTooShort()

	public function testPendingFieldsHashTheTokenAndStampAnExpiry(): void {
		$before = new DateTimeImmutable();

		$fields = $this->values->pendingFields('ann@example.org', 'secret-token', 'add');

		$this->assertSame('ann@example.org', $fields['pendingEmail']);
		$this->assertSame(hash('sha256', 'secret-token'), $fields['pendingEmailTokenHash']);
		$this->assertSame('add', $fields['pendingEmailMode']);
		// The raw token is never stored.
		$this->assertStringNotContainsString('secret-token', implode(' ', $fields));
		// The expiry is a day out, give or take the test's own runtime.
		$expires = new DateTimeImmutable($fields['pendingEmailExpiresAt']);
		$this->assertGreaterThan($before->modify('+23 hours'), $expires);
		$this->assertLessThan($before->modify('+25 hours'), $expires);

	}//end testPendingFieldsHashTheTokenAndStampAnExpiry()

	public function testPendingFieldsFallBackToReplaceForAnyModeButAdd(): void {
		$this->assertSame('replace', $this->values->pendingFields('a@b.nl', 't', 'replace')['pendingEmailMode']);
		$this->assertSame('replace', $this->values->pendingFields('a@b.nl', 't', 'whatever')['pendingEmailMode']);

	}//end testPendingFieldsFallBackToReplaceForAnyModeButAdd()

	public function testNeedsContactPromptWhenTheAccountHasNoEmailOrAsksForOne(): void {
		$this->assertFalse($this->values->needsContactPrompt(null));
		$this->assertFalse($this->values->needsContactPrompt(['email' => 'a@b.nl']));
		$this->assertTrue($this->values->needsContactPrompt(['email' => '']));
		$this->assertTrue($this->values->needsContactPrompt(['email' => 'a@b.nl', 'needsAlternativeContact' => true]));

	}//end testNeedsContactPromptWhenTheAccountHasNoEmailOrAsksForOne()

	public function testMaskKeepsOnlyTheFirstLetterAndTheDomain(): void {
		$this->assertSame('a***@example.org', $this->values->mask('ann@example.org'));
		// Nothing to mask: an address with no local part, or no @, masks to ''.
		$this->assertSame('', $this->values->mask('@example.org'));
		$this->assertSame('', $this->values->mask('no-at-sign'));
		$this->assertSame('', $this->values->mask(''));

	}//end testMaskKeepsOnlyTheFirstLetterAndTheDomain()
}//end class
