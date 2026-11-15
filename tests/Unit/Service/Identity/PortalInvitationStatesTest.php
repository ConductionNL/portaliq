<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\AddressInvitation;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The refusals of an address invitation, the lookup of an invitation by its
 * id, and the state an invitation reads as at a moment.
 */
class PortalInvitationStatesTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * The service over the fake store.
	 *
	 * @param ISecureRandom|null      $random The secret source.
	 * @param PortalObjectWriter|null $writer The writer.
	 *
	 * @return PortalInvitationService
	 */
	private function service(?ISecureRandom $random=null, ?PortalObjectWriter $writer=null): PortalInvitationService {
		$accounts = $this->getMockBuilder(PortalAccountService::class)->disableOriginalConstructor()->onlyMethods(['provision'])->getMock();

		return new PortalInvitationService($this->fakeReader(), ($writer ?? $this->fakeWriter()), $accounts, ($random ?? $this->fakeRandom()));
	}//end service()

	/**
	 * Without an address, a tenant or a clerk, without a secret, or when the
	 * row is not stored, nobody is invited.
	 *
	 * @return void
	 */
	public function testAnAddressInvitationIsRefused(): void {
		$service = $this->service();
		$this->assertNull($service->inviteAddress(invitation: new AddressInvitation(email: '', organisation: 'org', audience: 'client', invitedBy: 'clerk', ttl: '')));
		$this->assertNull($service->inviteAddress(invitation: new AddressInvitation(email: 'a@b.nl', organisation: '', audience: 'client', invitedBy: 'clerk', ttl: '')));
		$this->assertNull($service->inviteAddress(invitation: new AddressInvitation(email: 'a@b.nl', organisation: 'org', audience: 'client', invitedBy: '', ttl: '')));
		$this->assertSame([], $this->storedRows('portalInvitation'));

		$noSecret = $this->createMock(ISecureRandom::class);
		$noSecret->method('generate')->willReturn('');
		$this->assertNull($this->service(random: $noSecret)->inviteAddress(invitation: new AddressInvitation(email: 'a@b.nl', organisation: 'org', audience: 'client', invitedBy: 'clerk', ttl: '')));

		$notStored = $this->createMock(PortalObjectWriter::class);
		$notStored->method('createObject')->willReturn(null);
		$this->assertNull($this->service(writer: $notStored)->inviteAddress(invitation: new AddressInvitation(email: 'a@b.nl', organisation: 'org', audience: 'client', invitedBy: 'clerk', ttl: '')));

		$invited = $service->inviteAddress(invitation: new AddressInvitation(email: 'a@b.nl', organisation: 'org', audience: 'client', invitedBy: 'clerk', ttl: ''));
		$this->assertSame('secret-1', $invited['token']);
		$this->assertSame($this->storedRows('portalInvitation')[0]['expiresAt'], $invited['expiresAt']);
	}//end testAnAddressInvitationIsRefused()

	/**
	 * An invitation is found by its uuid, its flat id or its `@self` id, in
	 * its own tenant only; an empty id or tenant finds nothing.
	 *
	 * @return void
	 */
	public function testAnInvitationIsFoundByAnyOfItsIds(): void {
		$service = $this->service();
		$this->seedRow('portalInvitation', ['organisation' => 'org', 'state' => 'sent', 'id' => 'flat-1']);
		$this->seedRow('portalInvitation', ['organisation' => 'org', 'state' => 'sent', '@self' => ['id' => 'self-2']]);
		$uuid = $this->seedRow('portalInvitation', ['organisation' => 'org', 'state' => 'sent']);

		$this->assertSame('not_found', $service->revoke(id: '', organisation: 'org'));
		$this->assertSame('not_found', $service->revoke(id: 'flat-1', organisation: ''));
		$this->assertSame('not_found', $service->revoke(id: 'flat-1', organisation: 'other'));
		$this->assertSame('', $service->revoke(id: 'flat-1', organisation: 'org'));
		$this->assertSame('', $service->revoke(id: 'self-2', organisation: 'org'));
		$this->assertSame('', $service->revoke(id: $uuid, organisation: 'org'));
		$this->assertSame(['revoked', 'revoked', 'revoked'], array_column($this->storedRows('portalInvitation'), 'state'));
	}//end testAnInvitationIsFoundByAnyOfItsIds()

	/**
	 * A final state stays; an open one reads expired once its moment has
	 * come or when that moment does not parse, and as stored without one.
	 *
	 * @return void
	 */
	public function testTheStateAsOfAMoment(): void {
		$now = new DateTimeImmutable('2026-11-11T12:00:00+00:00');
		foreach ([
			['state' => 'expired', 'expiresAt' => '2027-01-01T00:00:00+00:00'],
			['state' => 'revoked', 'expiresAt' => '2026-01-01T00:00:00+00:00'],
			['state' => 'accepted', 'expiresAt' => '2026-01-01T00:00:00+00:00'],
			['state' => 'opened'],
			['state' => 'sent', 'expiresAt' => 'not a moment'],
			['state' => 'sent', 'expiresAt' => '2026-11-11T12:00:00+00:00'],
			['state' => 'opened', 'expiresAt' => '2026-11-11T12:00:01+00:00'],
			['expiresAt' => '2027-01-01T00:00:00+00:00'],
		] as $row) {
			$this->seedRow('portalInvitation', $row + ['organisation' => 'org', 'invitedBy' => 'clerk']);
		}

		$states = array_column($this->service()->sentBy(invitedBy: 'clerk', organisation: 'org', now: $now), 'state');
		$this->assertSame(['expired', 'revoked', 'accepted', 'opened', 'expired', 'expired', 'opened', 'sent'], $states);
	}//end testTheStateAsOfAMoment()
}//end class
