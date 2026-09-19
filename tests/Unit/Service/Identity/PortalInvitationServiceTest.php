<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-003: an address is
 * invited into the portal, the sender sees what became of the invitation, and
 * an invitation past its expiry admits nobody.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalInvitationServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testAnInvitationStoresOnlyTheHashOfItsSecret(): void {
		$service = $this->service();

		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');

		$this->assertNotNull($invited);
		$row = $this->storedRows('portalInvitation')[0];
		$this->assertSame(hash('sha256', $invited['token']), $row['tokenHash']);
		$this->assertArrayNotHasKey('token', $row);
		$this->assertSame('sent', $row['state']);

	}//end testAnInvitationStoresOnlyTheHashOfItsSecret()

	/**
	 * The caller may set the window, and a window that is set is the one
	 * stored: the default week applies only when none was given.
	 *
	 * @return void
	 */
	public function testACallerSuppliedWindowIsTheOneStored(): void {
		$service = $this->service();

		$short = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna', ttl: 'P1D');
		$default = $service->invite(email: 'bram@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');

		$this->assertNotNull($short);
		$this->assertNotNull($default);
		$this->assertLessThan(
			strtotime($default['expiresAt']),
			strtotime($short['expiresAt']),
			'a one-day window must expire before the default week'
		);

	}//end testACallerSuppliedWindowIsTheOneStored()


	public function testTheSenderSeesTheStateOfWhatTheySent(): void {
		$service = $this->service();
		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		$service->open(token: $invited['token']);

		$sent = $service->sentBy(invitedBy: 'clerk-anna', organisation: 'gemeente-x');

		$this->assertCount(1, $sent);
		$this->assertSame('opened', $sent[0]['state']);
		$this->assertSame('ans@example.org', $sent[0]['email']);

	}//end testTheSenderSeesTheStateOfWhatTheySent()

	public function testTheSenderSeesNobodyElsesInvitations(): void {
		$service = $this->service();
		$service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');

		$this->assertSame([], $service->sentBy(invitedBy: 'clerk-bob', organisation: 'gemeente-x'));

	}//end testTheSenderSeesNobodyElsesInvitations()

	public function testAnExpiredInvitationReadsExpiredAndAdmitsNobody(): void {
		$service = $this->service();
		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		$later = new DateTimeImmutable('+30 days');

		$this->assertSame('expired', $service->open(token: $invited['token'], now: $later)['state']);
		$this->assertNull($service->accept(token: $invited['token'], now: $later));
		$this->assertSame('expired', $service->sentBy(invitedBy: 'clerk-anna', organisation: 'gemeente-x', now: $later)[0]['state']);

	}//end testAnExpiredInvitationReadsExpiredAndAdmitsNobody()

	public function testAcceptingProvisionsTheAccountForTheInvitedAddress(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->once())
			->method('provision')
			->with(
				$this->equalTo('client'),
				$this->equalTo('gemeente-x'),
				$this->equalTo(''),
				$this->equalTo(''),
				$this->equalTo('ans@example.org'),
				$this->equalTo(true),
				$this->equalTo('clerk-anna'),
				$this->equalTo('')
			)
			->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		$service = $this->service(accounts: $accounts);
		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');

		$accepted = $service->accept(token: $invited['token']);

		$this->assertSame('subject-1', $accepted['subjectRef']);
		$this->assertSame('accepted', $this->storedRows('portalInvitation')[0]['state']);

	}//end testAcceptingProvisionsTheAccountForTheInvitedAddress()

	public function testAnInvitationIsAcceptedOnlyOnce(): void {
		$service = $this->service();
		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		$service->accept(token: $invited['token']);

		$this->assertNull($service->accept(token: $invited['token']));

	}//end testAnInvitationIsAcceptedOnlyOnce()

	public function testAnUnknownSecretMatchesNothing(): void {
		$service = $this->service();
		$service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');

		$this->assertNull($service->open(token: 'not-the-secret'));
		$this->assertNull($service->accept(token: ''));

	}//end testAnUnknownSecretMatchesNothing()

	/**
	 * The service over the fake store.
	 *
	 * @param PortalAccountService|null $accounts An account double, or the default.
	 *
	 * @return PortalInvitationService
	 */
	private function service(?PortalAccountService $accounts = null): PortalInvitationService {
		if ($accounts === null) {
			$accounts = $this->accounts();
			$accounts->method('provision')->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		}

		return new PortalInvitationService($this->fakeReader(), $this->fakeWriter(), $accounts, $this->fakeRandom());
	}//end service()

	/**
	 * A double that can only answer methods the real service has.
	 *
	 * @return PortalAccountService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function accounts(): PortalAccountService {
		return $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision'])
			->getMock();
	}//end accounts()

}//end class
