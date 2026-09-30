<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\ContactAddressBook;
use PHPUnit\Framework\TestCase;

/**
 * identity-profile-page REQ-IPP-003, design D2: the address rules, one per
 * test.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-keep-several-addresses-one-of-each-kind-preferred-req-ipp-003
 */
class ContactAddressBookTest extends TestCase {

	public function testAnAccountFromBeforeReadsItsAddressAsConfirmedAndPreferred(): void {
		$entries = (new ContactAddressBook())->entries(['email' => 'a@example.nl']);

		$this->assertSame([['kind' => 'email', 'value' => 'a@example.nl', 'confirmed' => true, 'preferred' => true]], $entries);

	}//end testAnAccountFromBeforeReadsItsAddressAsConfirmedAndPreferred()

	public function testANewEmailAddressIsAddedUnconfirmedAndAsksForTheMail(): void {
		$book = new ContactAddressBook();

		$added = $book->add(entries: $book->entries(['email' => 'a@example.nl']), kind: 'email', value: 'b@example.nl');

		$this->assertSame('', $added['refusal']);
		$this->assertTrue($added['confirm']);
		$this->assertSame(['kind' => 'email', 'value' => 'b@example.nl', 'confirmed' => false, 'preferred' => false], $added['entries'][1]);
		$this->assertSame('a@example.nl', $book->preferred(entries: $added['entries'], kind: 'email'));

	}//end testANewEmailAddressIsAddedUnconfirmedAndAsksForTheMail()

	public function testAnUnconfirmedAddressCannotBePreferred(): void {
		$book = new ContactAddressBook();
		$added = $book->add(entries: $book->entries(['email' => 'a@example.nl']), kind: 'email', value: 'c@example.nl');

		$preferred = $book->prefer(entries: $added['entries'], kind: 'email', value: 'c@example.nl');

		$this->assertSame('confirm_first', $preferred['refusal']);
		$this->assertSame('a@example.nl', $book->preferred(entries: $preferred['entries'], kind: 'email'));

	}//end testAnUnconfirmedAddressCannotBePreferred()

	public function testAConfirmedSecondAddressBecomesThePreferredOne(): void {
		$book = new ContactAddressBook();
		$added = $book->add(entries: $book->entries(['email' => 'a@example.nl']), kind: 'email', value: 'b@example.nl');
		$confirmed = $book->confirm(entries: $added['entries'], email: 'b@example.nl', mode: 'add');
		$this->assertSame('a@example.nl', $book->preferred(entries: $confirmed, kind: 'email'), 'an added address does not take over on confirmation');

		$preferred = $book->prefer(entries: $confirmed, kind: 'email', value: 'b@example.nl');

		$this->assertSame('', $preferred['refusal']);
		$this->assertSame('b@example.nl', $book->preferred(entries: $preferred['entries'], kind: 'email'));
		$this->assertCount(1, array_filter($preferred['entries'], static fn (array $e): bool => $e['kind'] === 'email' && $e['preferred'] === true));

	}//end testAConfirmedSecondAddressBecomesThePreferredOne()

	public function testAChangedAddressTakesOverOnceConfirmed(): void {
		$book = new ContactAddressBook();

		$confirmed = $book->confirm(entries: $book->entries(['email' => 'a@example.nl']), email: 'n@example.nl', mode: 'replace');

		$this->assertSame('n@example.nl', $book->preferred(entries: $confirmed, kind: 'email'));

	}//end testAChangedAddressTakesOverOnceConfirmed()

	public function testThePreferredAddressCannotBeRemovedWhileAnotherConfirmedOneExists(): void {
		$book = new ContactAddressBook();
		$entries = $book->confirm(entries: $book->entries(['email' => 'a@example.nl']), email: 'b@example.nl', mode: 'add');

		$this->assertSame('choose_another_preferred', $book->remove(entries: $entries, kind: 'email', value: 'a@example.nl')['refusal']);

		$removed = $book->remove(entries: $entries, kind: 'email', value: 'b@example.nl');
		$this->assertSame('', $removed['refusal']);
		$this->assertCount(1, $removed['entries']);

	}//end testThePreferredAddressCannotBeRemovedWhileAnotherConfirmedOneExists()

	public function testFivePendingAddressesAreTheMost(): void {
		$book = new ContactAddressBook();
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entries = $book->add(entries: $entries, kind: 'email', value: "p{$i}@example.nl")['entries'];
		}

		$this->assertSame('too_many_pending', $book->add(entries: $entries, kind: 'email', value: 'p6@example.nl')['refusal']);
		$again = $book->add(entries: $entries, kind: 'email', value: 'p3@example.nl');
		$this->assertSame('', $again['refusal'], 'asking again for a pending address sends a fresh mail');
		$this->assertTrue($again['confirm']);
		$this->assertCount(5, $again['entries']);

	}//end testFivePendingAddressesAreTheMost()

	public function testAPhoneNumberIsStoredInE164AndTheFirstIsPreferred(): void {
		$book = new ContactAddressBook();

		$this->assertSame('+31612345678', $book->normalise(kind: 'phone', value: '06 1234 5678'));
		$this->assertSame('+3243219876', $book->normalise(kind: 'phone', value: '0032 4321 9876'));
		$this->assertNull($book->normalise(kind: 'phone', value: 'bel me'));
		$this->assertNull($book->normalise(kind: 'email', value: 'nieuw-at-example'));

		$added = $book->add(entries: [], kind: 'phone', value: '+31612345678');
		$this->assertFalse($added['confirm'], 'a phone number gets no mail');
		$this->assertSame('+31612345678', $book->preferred(entries: $added['entries'], kind: 'phone'));
		$second = $book->add(entries: $added['entries'], kind: 'phone', value: '+31201234567');
		$this->assertSame('+31612345678', $book->preferred(entries: $second['entries'], kind: 'phone'));
		$this->assertSame('exists', $book->add(entries: $second['entries'], kind: 'phone', value: '+31201234567')['refusal']);

	}//end testAPhoneNumberIsStoredInE164AndTheFirstIsPreferred()

	public function testThePromptAsksWhenNoAddressIsInUseOrDispatchFlaggedIt(): void {
		$book = new ContactAddressBook();

		$this->assertFalse($book->needsContactPrompt(['email' => 'a@example.nl']));
		$this->assertTrue($book->needsContactPrompt(['email' => '']));
		$this->assertTrue($book->needsContactPrompt(['email' => 'a@example.nl', 'needsAlternativeContact' => true]));
		$this->assertFalse($book->needsContactPrompt(null));

	}//end testThePromptAsksWhenNoAddressIsInUseOrDispatchFlaggedIt()

	public function testAPendingAddressIsShownMasked(): void {
		$this->assertSame('n***@example.nl', (new ContactAddressBook())->mask('nieuw@example.nl'));
		$this->assertSame('', (new ContactAddressBook())->mask(''));

	}//end testAPendingAddressIsShownMasked()
}//end class
