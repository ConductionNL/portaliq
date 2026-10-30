<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MandateController;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalMandateAdminService;
use OCA\Portaliq\Service\Intake\PortalCaseTypeChoices;
use OCA\Portaliq\Service\PortalResolver;
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
	 * Mails the mailer double was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = [];

	/**
	 * Whether the mailer double fails.
	 *
	 * @var bool
	 */
	private bool $mailFails = false;

	/**
	 * The portal the resolver double answers, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $site = ['slug' => 'zuiddrecht', 'organisation' => 'zuid'];

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

		$mailer = $this->createMock(PortalIdentityMailer::class);
		$mailer->method('send')->willReturnCallback(
			function (string $template, string $email, string $secret, string $organisation, ?array $portal = null, array $details = []): bool {
				$this->mails[] = ['template' => $template, 'email' => $email, 'secret' => $secret, 'organisation' => $organisation, 'details' => $details];
				return $this->mailFails === false;
			}
		);
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->method('bySubjectRef')->willReturnCallback(
			static fn (string $ref): ?array => ['ref-tom' => ['displayName' => 'Tom Visser', 'email' => 't.visser@example.nl'], 'abc' => ['displayName' => 'Sanne de Vries']][$ref] ?? null
		);
		$choices = $this->createMock(PortalCaseTypeChoices::class);
		$choices->method('forPortal')->willReturnCallback(
			static fn (string $portal): array => $portal === 'zuiddrecht' ? [['id' => 'ct-parkeren', 'title' => 'Parkeervergunning']] : []
		);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturnCallback(fn (): ?array => $this->site);

		return new MandateController($request, $session, $mandates, $mailer, $accounts, $choices, $portals);
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
	 * An accept that could not finish now answers 503, not "not found", so
	 * the browser keeps the secret and says try later.
	 *
	 * @return void
	 */
	public function testAnAcceptThatCouldNotFinishIs503(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('accept')->willReturnOnConsecutiveCalls(PortalMandateAdminService::TRY_LATER, PortalMandateAdminService::GONE);
		$c = $this->controller(self::SUBJECT, $mandates);

		$later = $c->accept('t');
		$this->assertSame(503, $later->getStatus());
		$this->assertSame(['error' => 'try_later'], $later->getData());
		$this->assertSame(404, $c->accept('t')->getStatus(), 'a spent invitation stays 404');
	}//end testAnAcceptThatCouldNotFinishIs503()

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
	 * T4: a mandate in the given list names the account that holds it;
	 * an invitation and a company holder stay as they are.
	 *
	 * @return void
	 */
	public function testTheGivenListNamesWhoHoldsAMandate(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('given')->willReturn([
			['kind' => 'mandate', 'id' => 'm1', 'holder' => 'subject:ref-tom', 'state' => 'active'],
			['kind' => 'mandate', 'id' => 'm2', 'holder' => 'kvk:12345678', 'state' => 'active'],
			['kind' => 'invitation', 'id' => 'i1', 'email' => 'mark@example.nl', 'state' => 'pending'],
		]);

		$items = $this->controller(self::SUBJECT, $mandates)->given()->getData()['items'];

		$this->assertSame(['Tom Visser', 't.visser@example.nl'], [$items[0]['name'], $items[0]['email']]);
		$this->assertArrayNotHasKey('name', $items[1]);
		$this->assertSame(['kind' => 'invitation', 'id' => 'i1', 'email' => 'mark@example.nl', 'state' => 'pending'], $items[2]);
	}//end testTheGivenListNamesWhoHoldsAMandate()

	/**
	 * T4: a mail that did not leave is said, and nothing is answered as sent.
	 *
	 * @return void
	 */
	public function testAnInvitationWhoseMailDidNotLeaveIs502(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);
		$mandates->method('invite')->willReturn(['token' => 'x']);
		$this->mailFails = true;

		$answer = $this->controller(self::SUBJECT, $mandates)->invite('a@b.nl');

		$this->assertSame(502, $answer->getStatus());
		$this->assertSame(['error' => 'not_sent'], $answer->getData());
	}//end testAnInvitationWhoseMailDidNotLeaveIs502()

	/**
	 * T4: the case types for the scope choice come from the visited portal,
	 * and only when it is the session's own tenant.
	 *
	 * @return void
	 */
	public function testTheCaseTypesComeFromTheSessionsOwnPortal(): void {
		$mandates = $this->createMock(PortalMandateAdminService::class);

		$this->assertSame(['items' => [['id' => 'ct-parkeren', 'title' => 'Parkeervergunning']]], $this->controller(self::SUBJECT, $mandates)->caseTypes('zuiddrecht')->getData());
		$this->site = ['slug' => 'elders', 'organisation' => 'andere-gemeente'];
		$this->assertSame(['items' => []], $this->controller(self::SUBJECT, $mandates)->caseTypes('elders')->getData(), 'another tenant');
		$this->site = null;
		$this->assertSame(['items' => []], $this->controller(self::SUBJECT, $mandates)->caseTypes('weg')->getData());
		$this->assertSame(401, $this->controller(null, $mandates)->caseTypes('zuiddrecht')->getStatus());
		$acting = self::SUBJECT + ['actingUnderMandate' => true];
		$this->assertSame(403, $this->controller($acting, $mandates)->caseTypes('zuiddrecht')->getStatus());
	}//end testTheCaseTypesComeFromTheSessionsOwnPortal()

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

		$this->assertSame(['sent' => true], $c->invite('a@b.nl')->getData(), 'the secret never goes back to the inviter');
		$this->assertSame('mandate-invitation', $this->mails[0]['template']);
		$this->assertSame(['a@b.nl', 'x', 'zuid'], [$this->mails[0]['email'], $this->mails[0]['secret'], $this->mails[0]['organisation']]);
		$this->assertSame(['inviter' => 'Sanne de Vries'], $this->mails[0]['details']);
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
