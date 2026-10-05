<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-006: a new address is
 * used for nothing until the link in it is followed, and a removal takes the
 * account and its claims while the case stays with the municipality.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalSelfServiceServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testTheOldAddressStaysInUseUntilTheNewOneIsConfirmed(): void {
		$this->seedAccount();
		$service = $this->service();

		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'nieuw@example.org');

		$this->assertNotSame('', $changed['confirmationToken']);
		$account = $this->account();
		$this->assertSame('oud@example.org', $account['email']);
		$this->assertSame('nieuw@example.org', $account['pendingEmail']);

	}//end testTheOldAddressStaysInUseUntilTheNewOneIsConfirmed()

	public function testTheNewAddressIsInUseOnceTheLinkIsFollowed(): void {
		$this->seedAccount();
		$service = $this->service();
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'nieuw@example.org');

		$confirmed = $service->confirmEmail(token: $changed['confirmationToken']);

		$this->assertSame('nieuw@example.org', $confirmed['email']);
		$account = $this->account();
		$this->assertSame('nieuw@example.org', $account['email']);
		$this->assertSame('', $account['pendingEmail']);
		$this->assertTrue($account['verifiedEmail']);

	}//end testTheNewAddressIsInUseOnceTheLinkIsFollowed()

	public function testAnExpiredConfirmationLeavesTheOldAddressInPlace(): void {
		$this->seedAccount();
		$service = $this->service();
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'nieuw@example.org');

		$this->assertNull($service->confirmEmail(token: $changed['confirmationToken'], now: new DateTimeImmutable('+2 days')));
		$this->assertSame('oud@example.org', $this->account()['email']);

	}//end testAnExpiredConfirmationLeavesTheOldAddressInPlace()

	public function testSomethingThatIsNotAnAddressIsRefused(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNull($service->updateDetails(subjectRef: 'subject-1', email: 'nieuw-at-example'));
		$this->assertSame('oud@example.org', $this->account()['email']);

	}//end testSomethingThatIsNotAnAddressIsRefused()

	public function testTheAccountAndItsClaimsGoAndTheCaseStays(): void {
		$this->seedAccount();
		$this->seedRow('portalCase', ['subjectRef' => 'subject-1', 'reference' => 'ZAAK-1', 'organisation' => 'gemeente-x']);
		$service = $this->service();

		$this->assertTrue($service->removeAccount(subjectRef: 'subject-1'));

		$account = $this->account();
		$this->assertSame('removed', $account['status']);
		$this->assertSame([], $account['claims']);
		$this->assertSame('', $account['email']);
		$this->assertSame('', $account['identityRef']);
		$this->assertSame('ZAAK-1', $this->storedRows('portalCase')[0]['reference']);

	}//end testTheAccountAndItsClaimsGoAndTheCaseStays()

	public function testARemovedAccountCannotBeChangedAgain(): void {
		$this->seedAccount();
		$service = $this->service();
		$service->removeAccount(subjectRef: 'subject-1');

		$this->assertNull($service->updateDetails(subjectRef: 'subject-1', displayName: 'Iemand anders'));
		$this->assertFalse($service->removeAccount(subjectRef: 'subject-1'));

	}//end testARemovedAccountCannotBeChangedAgain()

	public function testAnUnknownSubjectChangesNothing(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNull($service->updateDetails(subjectRef: 'somebody-else', displayName: 'Iemand anders'));
		$this->assertSame('Ans de Vries', $this->account()['displayName']);

	}//end testAnUnknownSubjectChangesNothing()

	/**
	 * notification-preferences-per-role REQ: setting the channel opt-out
	 * changes only that field.
	 *
	 * @return void
	 */
	public function testOptingOutOfEmailChangesOnlyThatField(): void {
		$this->seedAccount();
		$service = $this->service();

		$changed = $service->updateDetails(subjectRef: 'subject-1', emailNotifications: false);

		$this->assertSame(expected: '', actual: $changed['confirmationToken']);
		$account = $this->account();
		$this->assertSame(expected: false, actual: $account['notificationChannels']['email']);
		$this->assertSame(expected: 'oud@example.org', actual: $account['email']);

	}//end testOptingOutOfEmailChangesOnlyThatField()

	/**
	 * Omitting the field entirely leaves it exactly as it was — including
	 * absent, for every account that predates this property.
	 *
	 * @return void
	 */
	public function testOmittingTheChannelPreferenceLeavesItUnset(): void {
		$this->seedAccount();
		$service = $this->service();

		$service->updateDetails(subjectRef: 'subject-1', displayName: 'Iemand anders');

		$this->assertArrayNotHasKey(key: 'notificationChannels', array: $this->account());

	}//end testOmittingTheChannelPreferenceLeavesItUnset()

	/**
	 * The preference alone is enough to ask for a change — it does not need
	 * a display name or email alongside it.
	 *
	 * @return void
	 */
	public function testTheChannelPreferenceAloneIsEnoughToAsk(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNotNull($service->updateDetails(subjectRef: 'subject-1', emailNotifications: true));

	}//end testTheChannelPreferenceAloneIsEnoughToAsk()

	/**
	 * translated-message-notice: a guardian picks, reads back and clears the
	 * language school messages are shown in; only that field changes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function testTheMessageLanguageIsSetReadBackAndCleared(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNotNull($service->updateDetails(subjectRef: 'subject-1', messageLanguage: 'ar'));
		$this->assertSame('ar', $this->account()['messageLanguage']);
		$this->assertSame('oud@example.org', $this->account()['email']);
		$this->assertArrayNotHasKey('notificationChannels', $this->account());
		$this->assertSame('ar', $service->messageLanguage(subjectRef: 'subject-1'));
		$this->assertSame(
			['displayName' => 'Ans de Vries', 'email' => 'oud@example.org', 'emailNotifications' => true, 'messageLanguage' => 'ar'],
			// identity-profile-page T05 adds the addresses and the channel to
			// the same read; this test is about the four it always had.
			array_intersect_key($service->details(subjectRef: 'subject-1'), array_flip(['displayName', 'email', 'emailNotifications', 'messageLanguage']))
		);

		$this->assertNotNull($service->updateDetails(subjectRef: 'subject-1', messageLanguage: ''));
		$this->assertSame('', $service->messageLanguage(subjectRef: 'subject-1'));

	}//end testTheMessageLanguageIsSetReadBackAndCleared()

	/**
	 * A value that is not a language tag is refused and changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function testAMessageLanguageThatIsNotATagIsRefused(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNull($service->updateDetails(subjectRef: 'subject-1', displayName: 'Nieuw', messageLanguage: 'Arabic please'));
		$this->assertArrayNotHasKey('messageLanguage', $this->account());
		$this->assertSame('Ans de Vries', $this->account()['displayName']);

	}//end testAMessageLanguageThatIsNotATagIsRefused()

	/**
	 * An unknown subject has no details and no language.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectHasNoDetails(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNull($service->details(subjectRef: 'someone-else'));
		$this->assertSame('', $service->messageLanguage(subjectRef: 'someone-else'));

	}//end testAnUnknownSubjectHasNoDetails()

	/**
	 * identity-profile-page T05 (REQ-IPP-001): the page's read shows the
	 * addresses, the masked pending address and the channel, and never the
	 * identity reference or a claim.
	 *
	 * @return void
	 */
	public function testTheDetailsShowAddressesAndChannelAndNoIdentity(): void {
		$this->seedAccount();
		$service = $this->service();
		$service->updateDetails(subjectRef: 'subject-1', email: 'nieuw@example.org');

		$details = $service->details(subjectRef: 'subject-1');

		$this->assertSame('portal', $details['contactChannel'], 'an account from before reads as portal only');
		$this->assertSame('n***@example.org', $details['pendingEmail']);
		$this->assertSame(
			[
				['kind' => 'email', 'value' => 'oud@example.org', 'confirmed' => true, 'preferred' => true],
				['kind' => 'email', 'value' => 'nieuw@example.org', 'confirmed' => false, 'preferred' => false],
			],
			$details['contactAddresses']
		);
		$this->assertArrayHasKey('notificationChannels', $details);
		$this->assertArrayNotHasKey('identityRef', $details);
		$this->assertArrayNotHasKey('claims', $details);
		$this->assertStringNotContainsString('bsn-1', (string)json_encode($details));

	}//end testTheDetailsShowAddressesAndChannelAndNoIdentity()

	/**
	 * identity-profile-page REQ-IPP-006: removal also takes the phone numbers
	 * and addresses, which are the person's data too.
	 *
	 * @return void
	 */
	public function testRemovalTakesEveryAddress(): void {
		$this->seedAccount();
		$service = $this->service();
		$service->updateDetails(subjectRef: 'subject-1', email: 'nieuw@example.org');

		$service->removeAccount(subjectRef: 'subject-1');

		$this->assertSame([], $this->account()['contactAddresses']);

	}//end testRemovalTakesEveryAddress()

	/**
	 * confirmed-address-joins-the-waiting-account REQ-PIS-006: a guardian who
	 * signed in without an address (the broker gives none) confirms the
	 * address the school invited her on, and the invitation's claims arrive
	 * on the account she signed in with.
	 *
	 * @return void
	 */
	public function testConfirmingTheInvitedAddressJoinsTheWaitingAccount(): void {
		$this->seedAccount();
		$waiting = $this->seedWaiting();
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->with('claim', 'subject-1', 'gemeente-x', 'portaliq', 'portalAccount', $waiting);
		$service = $this->service(auditor: $auditor);
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'ouder@example.org');

		$this->assertSame('pending', $this->rows[$waiting]['status'], 'Nothing joins before the link is followed.');
		$this->assertNotNull($service->confirmEmail(token: $changed['confirmationToken']));

		$account = $this->account();
		$this->assertSame('guardian-7', $account['claims']['learniq']['guardianRef']);
		$this->assertSame('requester-77', $account['claims']['dossiq']['linkedRequesterId']);
		$this->assertSame('ouder@example.org', $account['email']);
		$this->assertSame('void', $this->rows[$waiting]['status']);
		$this->assertNotSame('', (string)$this->rows[$waiting]['voidReason']);

	}//end testConfirmingTheInvitedAddressJoinsTheWaitingAccount()

	/**
	 * An address added beside the one in use (mode `add`) is confirmed just
	 * the same, so it joins too.
	 *
	 * @return void
	 */
	public function testAnAddressConfirmedBesideTheOneInUseJoinsToo(): void {
		$this->seedAccount();
		$waiting = $this->seedWaiting();
		$this->rows['uuid-1'] = array_merge($this->rows['uuid-1'], [
			'contactAddresses' => [
				['kind' => 'email', 'value' => 'oud@example.org', 'confirmed' => true, 'preferred' => true],
				['kind' => 'email', 'value' => 'ouder@example.org', 'confirmed' => false, 'preferred' => false],
			],
			'pendingEmail' => 'ouder@example.org',
			'pendingEmailMode' => 'add',
			'pendingEmailTokenHash' => hash('sha256', 'added-secret'),
			'pendingEmailExpiresAt' => (new DateTimeImmutable('+1 day'))->format(DATE_ATOM),
		]);

		$this->assertNotNull($this->service()->confirmEmail(token: 'added-secret'));

		$this->assertSame('oud@example.org', $this->account()['email']);
		$this->assertSame('guardian-7', $this->account()['claims']['learniq']['guardianRef']);
		$this->assertSame('void', $this->rows[$waiting]['status']);

	}//end testAnAddressConfirmedBesideTheOneInUseJoinsToo()

	/**
	 * Only the trust REQ-PIS-002 already gives: a waiting account whose
	 * address nobody verified, one on an identity reference, one in another
	 * organisation and one for another address all stay as they were, and
	 * nothing is recorded.
	 *
	 * @return void
	 */
	public function testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation(): void {
		$this->seedAccount();
		$unverified = $this->seedWaiting(['verifiedEmail' => false]);
		$onIdentity = $this->seedWaiting(['identityType' => 'digid', 'identityRef' => 'bsn-other']);
		$elsewhere  = $this->seedWaiting(['organisation' => 'gemeente-y']);
		$otherMail  = $this->seedWaiting(['email' => 'iemand@example.org']);
		$auditor    = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');
		$service = $this->service(auditor: $auditor);
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'ouder@example.org');

		$this->assertNotNull($service->confirmEmail(token: $changed['confirmationToken']));

		foreach ([$unverified, $onIdentity, $elsewhere, $otherMail] as $uuid) {
			$this->assertSame('pending', $this->rows[$uuid]['status']);
		}

		$this->assertArrayNotHasKey('learniq', $this->account()['claims']);

	}//end testAConfirmationJoinsOnlyAVerifiedEmailOnlyWaitingAccountInTheSameOrganisation()

	/**
	 * A link that admits nobody joins nothing: expired, unknown and spent.
	 *
	 * @return void
	 */
	public function testALinkThatAdmitsNobodyJoinsNothing(): void {
		$this->seedAccount();
		$waiting = $this->seedWaiting();
		$service = $this->service();
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'ouder@example.org');

		$this->assertNull($service->confirmEmail(token: $changed['confirmationToken'], now: new DateTimeImmutable('+2 days')));
		$this->assertNull($service->confirmEmail(token: 'not-the-secret'));
		$this->assertSame('pending', $this->rows[$waiting]['status']);

		$this->assertNotNull($service->confirmEmail(token: $changed['confirmationToken']));
		$this->assertNull($service->confirmEmail(token: $changed['confirmationToken']), 'The link works once.');

	}//end testALinkThatAdmitsNobodyJoinsNothing()

	/**
	 * A claim the account already holds is kept; the waiting account's other
	 * claims still arrive.
	 *
	 * @return void
	 */
	public function testAConfirmationNeverOverwritesAClaimTheAccountHolds(): void {
		$this->seedAccount();
		$this->rows['uuid-1']['claims']['learniq'] = ['guardianRef' => 'guardian-1'];
		$this->seedWaiting(['claims' => ['learniq' => ['guardianRef' => 'guardian-2', 'schoolRef' => 'school-9']]]);
		$service = $this->service();
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'ouder@example.org');

		$service->confirmEmail(token: $changed['confirmationToken']);

		$this->assertSame(['guardianRef' => 'guardian-1', 'schoolRef' => 'school-9'], $this->account()['claims']['learniq']);

	}//end testAConfirmationNeverOverwritesAClaimTheAccountHolds()

	/**
	 * An account that never signed in through an identity provider receives
	 * nothing: an address-only account confirming an address stays as it is.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutAnIdentityReceivesNothing(): void {
		$this->seedAccount();
		$this->rows['uuid-1']['identityRef'] = '';
		$waiting = $this->seedWaiting();
		$service = $this->service();
		$changed = $service->updateDetails(subjectRef: 'subject-1', email: 'ouder@example.org');

		$this->assertNotNull($service->confirmEmail(token: $changed['confirmationToken']));

		$this->assertSame('pending', $this->rows[$waiting]['status']);

	}//end testAnAccountWithoutAnIdentityReceivesNothing()

	/**
	 * Put a waiting account in the fake store: pending, address-only, its
	 * address verified by the school, carrying the school's claim.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return string The row's uuid.
	 */
	private function seedWaiting(array $overrides = []): string {
		return $this->seedRow('portalAccount', array_merge([
			'subjectRef' => 'waiting-' . count($this->rows),
			'organisation' => 'gemeente-x',
			'audience' => 'parent',
			'email' => 'ouder@example.org',
			'verifiedEmail' => true,
			'status' => 'pending',
			'provisionedBy' => 'learniq',
			'claims' => ['learniq' => ['guardianRef' => 'guardian-7']],
		], $overrides));
	}//end seedWaiting()

	/**
	 * The account row as it now stands.
	 *
	 * @return array<string, mixed>
	 */
	private function account(): array {
		return $this->storedRows('portalAccount')[0];
	}//end account()

	/**
	 * Put one active account in the fake store.
	 *
	 * @return void
	 */
	private function seedAccount(): void {
		$this->seedRow('portalAccount', [
			'subjectRef' => 'subject-1',
			'organisation' => 'gemeente-x',
			'audience' => 'client',
			'identityType' => 'digid',
			'identityRef' => 'bsn-1',
			'displayName' => 'Ans de Vries',
			'email' => 'oud@example.org',
			'status' => 'active',
			'claims' => ['dossiq' => ['linkedRequesterId' => 'requester-77']],
		]);
	}//end seedAccount()

	/**
	 * The service over the fake store, with an account service that reads it.
	 *
	 * @param AuditTrailService|null $auditor The audit trail, when a test watches it.
	 *
	 * @return PortalSelfServiceService
	 */
	private function service(?AuditTrailService $auditor = null): PortalSelfServiceService {
		$accounts = $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef'])
			->getMock();
		$accounts->method('findBySubjectRef')->willReturnCallback(
			function (string $subjectRef): ?array {
				foreach ($this->storedRows('portalAccount') as $row) {
					if (($row['subjectRef'] ?? '') === $subjectRef) {
						return $row;
					}
				}

				return null;
			}
		);

		return new PortalSelfServiceService($accounts, $this->fakeReader(), $this->fakeWriter(), $this->fakeRandom(), auditor: $auditor);
	}//end service()

}//end class
