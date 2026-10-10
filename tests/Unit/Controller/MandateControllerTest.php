<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MandateController;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\Identity\PortalMandateAdminService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Status mapping of the mandate endpoints: 401 without a session, 403 for a
 * non-manager, and the service outcomes as 4xx or payloads.
 */
#[CoversClass(MandateController::class)]
#[UsesClass(MandateParties::class)]
class MandateControllerTest extends TestCase {
	private const SUBJECT = ['provider' => 'digid', 'subjectRef' => 'abc', 'organisation' => 'zuid'];

	/**
	 * Build the controller over doubles.
	 *
	 * @param array<string, mixed>|null $subject The session subject.
	 * @param MockObject                $mandates The admin service double.
	 *
	 * @return MandateController
	 */
	private function controller(?array $subject, MockObject $mandates): MandateController {
		$request = $this->createMock(IRequest::class);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new MandateController($request, $session, $mandates);
	}//end controller()

	/**
	 * Without a session every endpoint answers 401 and the service is idle.
	 *
	 * @return void
	 */
	public function testNoSessionIs401(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->expects($this->never())->method($this->anything());
		$c = $this->controller(null, $mandates);

		foreach ([$c->given(), $c->invite('a@b.nl'), $c->accept('t'), $c->revokeInvitation('1'), $c->revoke('1'), $c->expiry('1'), $c->held(), $c->stop('1')] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['authenticated' => false], $response->getData());
		}
	}//end testNoSessionIs401()

	/**
	 * A session that cannot manage (no organisation, or acting under a mandate) gets 403.
	 *
	 * @return void
	 */
	public function testNonManagerIs403(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->expects($this->never())->method($this->anything());

		$noOrg = $this->controller(['subjectRef' => 'abc'], $mandates);
		$this->assertSame(403, $noOrg->given()->getStatus());

		$acting = $this->controller(self::SUBJECT + ['actingUnderMandate' => true], $mandates);
		$this->assertSame(403, $acting->invite('a@b.nl')->getStatus());
		$this->assertSame(403, $acting->revoke('1')->getStatus());

		$noParty = $this->controller(['provider' => 'eherkenning', 'organisation' => 'zuid'], $mandates);
		$this->assertSame(403, $noParty->revokeInvitation('1')->getStatus());
		$this->assertSame(403, $noParty->expiry('1', '2030-01-01')->getStatus());
	}//end testNonManagerIs403()

	/**
	 * given() and invite() pass the party and organisation through.
	 *
	 * @return void
	 */
	public function testGivenAndInvite(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('given')->with('subject:abc', 'zuid')->willReturn([['id' => '1']]);
		$mandates->method('invite')->willReturnOnConsecutiveCalls(['token' => 'x'], PortalMandateAdminService::BAD_EMAIL);
		$c = $this->controller(self::SUBJECT, $mandates);

		$given = $c->given();
		$this->assertSame(200, $given->getStatus());
		$this->assertSame(['party' => 'subject:abc', 'items' => [['id' => '1']]], $given->getData());

		$this->assertSame(['token' => 'x'], $c->invite('a@b.nl')->getData());
		$bad = $c->invite('nope');
		$this->assertSame(400, $bad->getStatus());
		$this->assertSame(['error' => 'bad_email'], $bad->getData());
	}//end testGivenAndInvite()

	/**
	 * accept() maps the service outcome to 200, 409 or 404.
	 *
	 * @return void
	 */
	public function testAccept(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('accept')->willReturnOnConsecutiveCalls(['id' => 'm'], PortalMandateAdminService::OWN_INVITATION, PortalMandateAdminService::NOT_FOUND);
		$c = $this->controller(self::SUBJECT, $mandates);

		$this->assertSame(['id' => 'm'], $c->accept('t')->getData());
		$this->assertSame(409, $c->accept('t')->getStatus());
		$missing = $c->accept('t');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'not_found'], $missing->getData());
	}//end testAccept()

	/**
	 * Revoke endpoints answer 204 on success and 404 otherwise.
	 *
	 * @return void
	 */
	public function testRevokesAndStop(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('revokeInvitation')->willReturnOnConsecutiveCalls('', PortalMandateAdminService::NOT_FOUND);
		$mandates->method('revoke')->with('subject:abc', 'zuid', '7', 'abc')->willReturn('');
		$mandates->method('stop')->with(['subject:abc'], 'zuid', '7', 'abc')->willReturnOnConsecutiveCalls('', PortalMandateAdminService::NOT_FOUND);
		$c = $this->controller(self::SUBJECT, $mandates);

		$this->assertSame(204, $c->revokeInvitation('7')->getStatus());
		$this->assertSame(404, $c->revokeInvitation('7')->getStatus());
		$this->assertSame(204, $c->revoke('7')->getStatus());
		$this->assertSame(204, $c->stop('7')->getStatus());
		$this->assertSame(404, $c->stop('7')->getStatus());
	}//end testRevokesAndStop()

	/**
	 * expiry() distinguishes a bad date (400), a gone mandate (409), success and missing.
	 *
	 * @return void
	 */
	public function testExpiry(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('setExpiry')->willReturnOnConsecutiveCalls(
			PortalMandateAdminService::BAD_END_DATE,
			PortalMandateAdminService::GONE,
			'',
			PortalMandateAdminService::NOT_FOUND
		);
		$c = $this->controller(self::SUBJECT, $mandates);

		$this->assertSame(400, $c->expiry('1', 'x')->getStatus());
		$this->assertSame(409, $c->expiry('1', 'x')->getStatus());
		$this->assertSame(204, $c->expiry('1', 'x')->getStatus());
		$this->assertSame(404, $c->expiry('1', 'x')->getStatus());
	}//end testExpiry()

	/**
	 * held() lists by the session's holder parties.
	 *
	 * @return void
	 */
	public function testHeld(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('held')->with(['subject:abc', 'kvk:12345678'], 'zuid')->willReturn([['id' => 'h']]);
		$c = $this->controller(array_merge(self::SUBJECT, ['provider' => 'eherkenning', 'kvk' => '12345678']), $mandates);

		$this->assertSame(['items' => [['id' => 'h']]], $c->held()->getData());
	}//end testHeld()
}//end class
