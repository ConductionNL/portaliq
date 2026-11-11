<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\PortalAccountCreation;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rows PortalReferenceLinkService::issue() and
 * PortalInvitationService::invite() write, every refusal (nothing written),
 * the answer they give, and that the stored hash opens the row again.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class IdentityTokenRowsTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	/**
	 * Refused reference links write nothing.
	 *
	 * @return void
	 */
	public function testAReferenceLinkIsRefusedWithoutItsRouteOrItsFields(): void {
		$links = $this->links();
		$type  = ['portalIdentityKind' => ['reference']];

		$this->assertNull($links->issue(caseType: [], caseReference: 'Z-1', email: 'a@b.nl', organisation: 'org'));
		$this->assertNull($links->issue(caseType: ['portalIdentityKind' => 'reference'], caseReference: 'Z-1', email: 'a@b.nl', organisation: 'org'));
		$this->assertNull($links->issue(caseType: ['portalIdentityKind' => ['digid']], caseReference: 'Z-1', email: 'a@b.nl', organisation: 'org'));
		$this->assertNull($links->issue(caseType: $type, caseReference: '', email: 'a@b.nl', organisation: 'org'));
		$this->assertNull($links->issue(caseType: $type, caseReference: 'Z-1', email: '', organisation: 'org'));
		$this->assertNull($links->issue(caseType: $type, caseReference: 'Z-1', email: 'a@b.nl', organisation: ''));
		$this->assertSame([], $this->rows);

	}//end testAReferenceLinkIsRefusedWithoutItsRouteOrItsFields()

	/**
	 * The reference link row, and the answer.
	 *
	 * @return void
	 */
	public function testAReferenceLinkRowCarriesTheCaseAndADayToLive(): void {
		$issued = $this->links()->issue(
			caseType: ['portalIdentityKind' => ['digid', 'reference']],
			caseReference: 'Z-1',
			email: 'a@b.nl',
			organisation: 'org',
			caseRegister: 'zaken',
			caseSchema: 'zaak'
		);

		$this->assertSame('secret-1', $issued['token']);
		$row = $this->storedRows('portalReferenceLink')[0];
		$this->assertSame($issued['expiresAt'], $row['expiresAt']);
		$this->assertSame('+1 day', $this->span(from: $row['issuedAt'], to: $row['expiresAt']));
		unset($row['issuedAt'], $row['expiresAt'], $row['uuid'], $row['_schema']);
		$this->assertSame(
			[
				'caseReference' => 'Z-1',
				'caseRegister' => 'zaken',
				'caseSchema' => 'zaak',
				'email' => 'a@b.nl',
				'organisation' => 'org',
				'tokenHash' => hash('sha256', 'secret-1'),
				'state' => 'sent',
			],
			$row
		);

	}//end testAReferenceLinkRowCarriesTheCaseAndADayToLive()

	/**
	 * Refused invitations write nothing.
	 *
	 * @return void
	 */
	public function testAnInvitationIsRefusedWithoutAnAddressATenantOrASender(): void {
		$invitations = $this->invitations();

		$this->assertNull($invitations->invite(email: '', organisation: 'org', audience: 'client', invitedBy: 'clerk'));
		$this->assertNull($invitations->invite(email: 'a@b.nl', organisation: '', audience: 'client', invitedBy: 'clerk'));
		$this->assertNull($invitations->invite(email: 'a@b.nl', organisation: 'org', audience: 'client', invitedBy: ''));
		$this->assertSame([], $this->rows);

	}//end testAnInvitationIsRefusedWithoutAnAddressATenantOrASender()

	/**
	 * The invitation row with its defaults, and with a given audience and window.
	 *
	 * @return void
	 */
	public function testAnInvitationRowCarriesItsAudienceAndWindow(): void {
		$invitations = $this->invitations();

		$first  = $invitations->invite(email: 'a@b.nl', organisation: 'org', audience: '', invitedBy: 'clerk');
		$second = $invitations->invite(email: 'c@d.nl', organisation: 'org', audience: 'supplier', invitedBy: 'clerk', ttl: 'PT2H');

		[$one, $two] = $this->storedRows('portalInvitation');
		$this->assertSame(['secret-1', $one['expiresAt']], [$first['token'], $first['expiresAt']]);
		$this->assertSame(['secret-2', $two['expiresAt']], [$second['token'], $second['expiresAt']]);
		$this->assertSame('+7 days', $this->span(from: $one['sentAt'], to: $one['expiresAt']));
		$this->assertSame('+2 hours', $this->span(from: $two['sentAt'], to: $two['expiresAt']));
		unset($one['sentAt'], $one['expiresAt'], $one['uuid'], $one['_schema']);
		$this->assertSame(
			[
				'email' => 'a@b.nl',
				'organisation' => 'org',
				'audience' => 'client',
				'tokenHash' => hash('sha256', 'secret-1'),
				'state' => 'sent',
				'invitedBy' => 'clerk',
			],
			$one
		);
		$this->assertSame('supplier', $two['audience']);

	}//end testAnInvitationRowCarriesItsAudienceAndWindow()

	/**
	 * Only the token whose hash is stored opens the row again.
	 *
	 * @return void
	 */
	public function testOnlyTheStoredHashOpensTheRow(): void {
		$links  = $this->links();
		$issued = $links->issue(caseType: ['portalIdentityKind' => ['reference']], caseReference: 'Z-1', email: 'a@b.nl', organisation: 'org');

		$this->assertNull($links->redeem(token: 'secret-2'));
		$this->assertNull($links->redeem(token: ''));
		$this->assertSame('Z-1', $links->redeem(token: $issued['token'])['caseReference']);
		$this->assertSame('uuid-1', $this->storedRows('portalReferenceLink')[0]['uuid']);

		$invitations = $this->invitations();
		$invited     = $invitations->invite(email: 'a@b.nl', organisation: 'org', audience: '', invitedBy: 'clerk');
		$this->assertNull($invitations->open(token: 'nope'));
		$this->assertNotNull($invitations->open(token: $invited['token']));

	}//end testOnlyTheStoredHashOpensTheRow()

	/**
	 * How far apart two ISO 8601 moments are, in words.
	 *
	 * @param string $from The earlier moment.
	 * @param string $to   The later moment.
	 *
	 * @return string Like `+1 day` or `+2 hours`.
	 */
	private function span(string $from, string $to): string {
		$seconds = (new DateTimeImmutable($to))->getTimestamp() - (new DateTimeImmutable($from))->getTimestamp();
		$spans   = [86400 => 'day', 3600 => 'hour'];
		foreach ($spans as $unit => $word) {
			if ($seconds % $unit === 0) {
				$count = intdiv($seconds, $unit);
				return '+' . $count . ' ' . $word . ($count === 1 ? '' : 's');
			}
		}

		return '+' . $seconds . ' seconds';
	}//end span()

	/**
	 * The reference link service over the fake store.
	 *
	 * @return PortalReferenceLinkService
	 */
	private function links(): PortalReferenceLinkService {
		return new PortalReferenceLinkService($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end links()

	/**
	 * The invitation service over the fake store.
	 *
	 * @return PortalInvitationService
	 */
	private function invitations(): PortalInvitationService {
		$accounts = $this->getMockBuilder(PortalAccountCreation::class)->disableOriginalConstructor()->getMock();
		return new PortalInvitationService($this->fakeReader(), $this->fakeWriter(), $accounts, $this->fakeRandom());
	}//end invitations()

}//end class
