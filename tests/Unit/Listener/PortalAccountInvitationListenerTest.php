<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Event\PortalAccountClaimRequestedEvent;
use OCA\Portaliq\Event\PortalAccountInvitationRequestedEvent;
use OCA\Portaliq\Listener\PortalAccountInvitationListener;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * invitation-secret-joins-the-signed-in-account REQ-PIS-007: an app asks
 * through a typed event, Portaliq mails the secret to the waiting account's
 * own address, and the event answers with a word, never with the secret.
 *
 * The event is the real class, so a listener calling an accessor it does not
 * have fails here.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class PortalAccountInvitationListenerTest extends TestCase {

	/**
	 * What reached the logger.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	public function testTheSecretIsMailedToTheWaitingAccountsAddressAndNeverAnswered(): void {
		$invitations = $this->invitations();
		$invitations->expects($this->once())->method('issue')->with('waiting-1', 'learniq')->willReturn(
			['token' => 'secret-abc', 'email' => 'ouder@example.org', 'organisation' => 'gemeente-x', 'expiresAt' => '2026-10-12T09:00:00+00:00']
		);
		$mailer = $this->mailer();
		$mailer->expects($this->once())->method('send')
			->with(PortalIdentityMailer::TEMPLATE_ACCOUNT_INVITATION, 'ouder@example.org', 'secret-abc', 'gemeente-x')
			->willReturn(true);
		$event = new PortalAccountInvitationRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1');

		$this->listener($invitations, $mailer)->handle($event);

		$this->assertSame(PortalAccountInvitationRequestedEvent::SENT, $event->getResult());
		$this->assertSame('2026-10-12T09:00:00+00:00', $event->getExpiresAt());
		// Nothing on the event carries the secret back to the app.
		$this->assertStringNotContainsString('secret-abc', serialize([$event->getResult(), $event->getExpiresAt(), $event->getAppId(), $event->getSubjectRef()]));
		$this->assertSame(
			['__construct', 'getAppId', 'getSubjectRef', 'answer', 'getResult', 'getExpiresAt'],
			array_values(array_filter(get_class_methods($event), static fn (string $m): bool => in_array($m, ['isPropagationStopped', 'stopPropagation'], true) === false))
		);

	}//end testTheSecretIsMailedToTheWaitingAccountsAddressAndNeverAnswered()

	public function testAnAccountThatMayNotBeInvitedIsRefusedAndNothingIsMailed(): void {
		$invitations = $this->invitations();
		$invitations->method('issue')->willReturn(null);
		$mailer = $this->mailer();
		$mailer->expects($this->never())->method('send');
		$event = new PortalAccountInvitationRequestedEvent(appId: 'dossiq', subjectRef: 'waiting-1');

		$this->listener($invitations, $mailer)->handle($event);

		$this->assertSame(PortalAccountInvitationRequestedEvent::REFUSED, $event->getResult());
		$this->assertSame('', $event->getExpiresAt());

	}//end testAnAccountThatMayNotBeInvitedIsRefusedAndNothingIsMailed()

	public function testAMailThatDidNotLeaveSaysSo(): void {
		$invitations = $this->invitations();
		$invitations->method('issue')->willReturn(['token' => 'secret-abc', 'email' => 'ouder@example.org', 'organisation' => 'gemeente-x', 'expiresAt' => 'x']);
		$mailer = $this->mailer();
		$mailer->method('send')->willReturn(false);
		$event = new PortalAccountInvitationRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1');

		$this->listener($invitations, $mailer)->handle($event);

		$this->assertSame(PortalAccountInvitationRequestedEvent::NOT_SENT, $event->getResult());

	}//end testAMailThatDidNotLeaveSaysSo()

	public function testAFailureIsARefusalNotAnExceptionAndItsMessageIsNotLogged(): void {
		$invitations = $this->invitations();
		$invitations->method('issue')->willThrowException(new RuntimeException('OpenRegister is down for ouder@example.org'));
		$event = new PortalAccountInvitationRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1');

		$this->listener($invitations, $this->mailer())->handle($event);

		$this->assertSame(PortalAccountInvitationRequestedEvent::REFUSED, $event->getResult());
		$this->assertStringNotContainsString('ouder@example.org', implode(' ', $this->logged));

	}//end testAFailureIsARefusalNotAnExceptionAndItsMessageIsNotLogged()

	public function testAnotherEventIsLeftAlone(): void {
		$invitations = $this->invitations();
		$invitations->expects($this->never())->method('issue');
		$event = new PortalAccountClaimRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1', claimName: 'guardianRef', value: 'guardian-7');

		$this->listener($invitations, $this->mailer())->handle($event);

		$this->assertSame('', $event->getResult());

	}//end testAnotherEventIsLeftAlone()

	/**
	 * The listener over its doubles and a recording logger.
	 *
	 * @param mixed $invitations The invitation service double.
	 * @param mixed $mailer The mailer double.
	 *
	 * @return PortalAccountInvitationListener
	 */
	private function listener(mixed $invitations, mixed $mailer): PortalAccountInvitationListener {
		$this->logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = $message . ' ' . json_encode($context);
			}
		);

		return new PortalAccountInvitationListener($invitations, $mailer, $logger);
	}//end listener()

	/**
	 * A double of the invitation service, limited to the method it has.
	 *
	 * @return mixed
	 */
	private function invitations(): mixed {
		return $this->getMockBuilder(WaitingAccountInvitation::class)->disableOriginalConstructor()->onlyMethods(['issue'])->getMock();
	}//end invitations()

	/**
	 * A double of the mailer, limited to the method it has.
	 *
	 * @return mixed
	 */
	private function mailer(): mixed {
		return $this->getMockBuilder(PortalIdentityMailer::class)->disableOriginalConstructor()->onlyMethods(['send'])->getMock();
	}//end mailer()
}//end class
