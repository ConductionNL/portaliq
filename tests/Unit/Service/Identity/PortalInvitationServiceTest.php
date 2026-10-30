<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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
		$this->rows       = [];
		$this->held       = [];
		$this->beforeLock = null;

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

	/**
	 * identity-staff-account-screens T02 (REQ-ISA-002): a withdrawn
	 * invitation admits nobody, and one that was accepted cannot be withdrawn.
	 *
	 * @return void
	 */
	public function testAWithdrawnInvitationAdmitsNobody(): void {
		$service = $this->service();
		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		$id = $this->storedRows('portalInvitation')[0]['uuid'];

		$this->assertSame('not_found', $service->revoke(id: $id, organisation: 'gemeente-y'), 'another organisation cannot withdraw it');
		$this->assertSame('', $service->revoke(id: $id, organisation: 'gemeente-x'));
		$this->assertSame('', $service->revoke(id: $id, organisation: 'gemeente-x'), 'withdrawing twice changes nothing');

		$this->assertSame('revoked', $this->storedRows('portalInvitation')[0]['state']);
		$this->assertNull($service->accept(token: $invited['token']));
		$this->assertSame('revoked', $service->sentBy(invitedBy: 'clerk-anna', organisation: 'gemeente-x')[0]['state']);

	}//end testAWithdrawnInvitationAdmitsNobody()

	/**
	 * An accepted invitation stays accepted.
	 *
	 * @return void
	 */
	public function testAnAcceptedInvitationCannotBeWithdrawn(): void {
		$service = $this->service();
		$invited = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		$this->assertNotNull($service->accept(token: $invited['token']));
		$id = $this->storedRows('portalInvitation')[0]['uuid'];

		$this->assertSame('already_accepted', $service->revoke(id: $id, organisation: 'gemeente-x'));
		$this->assertSame('accepted', $this->storedRows('portalInvitation')[0]['state']);

	}//end testAnAcceptedInvitationCannotBeWithdrawn()

	/**
	 * Sending an invitation again replaces its secret: the old link admits
	 * nobody, the new one does, and the window starts again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/staff-resend-an-invitation/specs/portal-account-administration/spec.md#requirement-staff-send-an-invitation-again-req-isa-011
	 */
	public function testResendingReplacesTheSecretAndRestartsTheWindow(): void {
		$service = $this->service();
		$first = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna', ttl: 'P1D');
		$id = $this->storedRows(schema: 'portalInvitation')[0]['uuid'];

		$again = $service->resend(id: $id, organisation: 'gemeente-x');

		$this->assertSame(expected: 'ans@example.org', actual: $again['email']);
		$this->assertNotSame(expected: $first['token'], actual: $again['token']);
		$this->assertNull(actual: $service->open(token: $first['token']));
		$this->assertNotNull(actual: $service->open(token: $again['token']));
		$this->assertGreaterThan(expected: strtotime(datetime: $first['expiresAt']), actual: strtotime(datetime: $again['expiresAt']));
		$this->assertSame(expected: hash(algo: 'sha256', data: $again['token']), actual: $this->storedRows(schema: 'portalInvitation')[0]['tokenHash']);
	}//end testResendingReplacesTheSecretAndRestartsTheWindow()

	/**
	 * An accepted or withdrawn invitation is not sent again, and another
	 * organisation cannot find it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/staff-resend-an-invitation/specs/portal-account-administration/spec.md#requirement-staff-send-an-invitation-again-req-isa-011
	 */
	public function testOnlyAnOpenInvitationIsSentAgain(): void {
		$service = $this->service();
		$accepted = $service->invite(email: 'ans@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		$service->accept(token: $accepted['token']);
		$service->invite(email: 'bram@example.org', organisation: 'gemeente-x', audience: 'client', invitedBy: 'clerk-anna');
		[$acceptedId, $withdrawnId] = array_column(array: $this->storedRows(schema: 'portalInvitation'), column_key: 'uuid');
		$service->revoke(id: $withdrawnId, organisation: 'gemeente-x');

		$this->assertSame(expected: ['error' => 'already_accepted'], actual: $service->resend(id: $acceptedId, organisation: 'gemeente-x'));
		$this->assertSame(expected: ['error' => 'withdrawn'], actual: $service->resend(id: $withdrawnId, organisation: 'gemeente-x'));
		$this->assertSame(expected: ['error' => 'not_found'], actual: $service->resend(id: $withdrawnId, organisation: 'gemeente-y'));
	}//end testOnlyAnOpenInvitationIsSentAgain()

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
	private function service(?PortalAccountService $accounts = null, ?PortalObjectWriter $writer = null): PortalInvitationService {
		if ($accounts === null) {
			$accounts = $this->accounts();
			$accounts->method('provision')->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		}

		return new PortalInvitationService($this->fakeReader(), ($writer ?? $this->fakeWriter()), $accounts, $this->fakeRandom(), new ClaimLock($this->fakeLocks(), 0));
	}//end service()

	/** @var array<string, bool> The locks held right now. */
	private array $held = [];

	/** @var callable|null Runs once, just before the next lock is taken. */
	private $beforeLock = null;

	/**
	 * A locking provider that holds exclusive locks in memory and refuses a
	 * taken one, the way Nextcloud's does.
	 *
	 * @return ILockingProvider
	 */
	private function fakeLocks(): ILockingProvider {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			function (string $path): void {
				$hook = $this->beforeLock;
				if ($hook !== null) {
					$this->beforeLock = null;
					$hook($path);
				}

				if (isset($this->held[$path]) === true) {
					throw new LockedException($path);
				}

				$this->held[$path] = true;
			}
		);
		$locks->method('releaseLock')->willReturnCallback(
			function (string $path): void {
				unset($this->held[$path]);
			}
		);

		return $locks;
	}//end fakeLocks()

	public function testTwoAcceptsOfOneInvitationProvisionOnce(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->once())->method('provision')->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		$service = $this->service($accounts);
		$invited = $service->invite(email: 'tom@example.nl', organisation: 'gemeente-x', audience: 'client', invitedBy: 'admin');

		// A second accept lands between the first one's arrival and its lock.
		$raced = null;
		$this->beforeLock = function () use ($service, $invited, &$raced): void {
			$raced = $service->accept(token: $invited['token']);
		};

		$this->assertNull($service->accept(token: $invited['token']), 'the invitation is spent once the lock is free');
		$this->assertSame(['subjectRef' => 'subject-1'], $raced);
	}

	public function testAnAcceptWhoseInvitationCannotBeSpentIsRefused(): void {
		$base   = $this->fakeWriter();
		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject', 'deleteObject'])
			->getMock();
		$writer->method('createObject')->willReturnCallback(fn (...$args) => $base->createObject(...$args));
		$writer->method('deleteObject')->willReturnCallback(fn (...$args) => $base->deleteObject(...$args));
		$writer->method('updateObject')->willReturn(null);

		$service = $this->service(null, $writer);
		$invited = $service->invite(email: 'tom@example.nl', organisation: 'gemeente-x', audience: 'client', invitedBy: 'admin');

		$this->assertSame(PortalInvitationService::TRY_LATER, $service->accept(token: $invited['token']), 'temporary, not an invalid invitation');
	}

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
