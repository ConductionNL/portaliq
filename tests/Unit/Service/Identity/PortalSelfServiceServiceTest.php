<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
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
	 * @return PortalSelfServiceService
	 */
	private function service(): PortalSelfServiceService {
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

		return new PortalSelfServiceService($accounts, $this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end service()

}//end class
