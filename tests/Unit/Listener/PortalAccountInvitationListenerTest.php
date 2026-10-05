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
		// A mailed invitation leaves the code slot empty: its secret goes by mail only.
		$this->assertSame('', $event->getCode());
		$this->assertSame(PortalAccountInvitationRequestedEvent::CHANNEL_MAIL, $event->getChannel());
		$this->assertSame(
			['__construct', 'getAppId', 'getSubjectRef', 'getChannel', 'answer', 'getCode', 'getResult', 'getExpiresAt'],
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

	/**
	 * invitation-code-from-a-letter REQ-PIS-009: for a letter the code is
	 * answered to the app, which prints it, and nothing is mailed.
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function testALetterGetsACodeAndNoMail(): void {
		$invitations = $this->invitations();
		$invitations->expects($this->never())->method('issue');
		$invitations->expects($this->once())->method('issueCode')->with('waiting-1', 'learniq')->willReturn(
			['code' => 'ABCD-EFGH-2345', 'expiresAt' => '2026-10-12T09:00:00+00:00']
		);
		$mailer = $this->mailer();
		$mailer->expects($this->never())->method('send');
		$event = new PortalAccountInvitationRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1', channel: PortalAccountInvitationRequestedEvent::CHANNEL_LETTER);

		$this->listener($invitations, $mailer)->handle($event);

		$this->assertSame(PortalAccountInvitationRequestedEvent::CODE, $event->getResult());
		$this->assertSame('ABCD-EFGH-2345', $event->getCode());
		$this->assertSame('2026-10-12T09:00:00+00:00', $event->getExpiresAt());

	}//end testALetterGetsACodeAndNoMail()

	public function testARefusedOrFailedCodeIsARefusal(): void {
		$invitations = $this->invitations();
		$invitations->method('issueCode')->willReturn(null);
		$event = new PortalAccountInvitationRequestedEvent(appId: 'dossiq', subjectRef: 'waiting-1', channel: 'letter');
		$this->listener($invitations, $this->mailer())->handle($event);
		$this->assertSame(PortalAccountInvitationRequestedEvent::REFUSED, $event->getResult());
		$this->assertSame('', $event->getCode());

		$failing = $this->invitations();
		$failing->method('issueCode')->willThrowException(new RuntimeException('down'));
		$second = new PortalAccountInvitationRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1', channel: 'letter');
		$this->listener($failing, $this->mailer())->handle($second);
		$this->assertSame(PortalAccountInvitationRequestedEvent::REFUSED, $second->getResult());

	}//end testARefusedOrFailedCodeIsARefusal()

	public function testAnUnknownChannelIsRefusedAndNothingIsIssued(): void {
		$invitations = $this->invitations();
		$invitations->expects($this->never())->method('issue');
		$invitations->expects($this->never())->method('issueCode');
		$event = new PortalAccountInvitationRequestedEvent(appId: 'learniq', subjectRef: 'waiting-1', channel: 'sms');

		$this->listener($invitations, $this->mailer())->handle($event);

		$this->assertSame(PortalAccountInvitationRequestedEvent::REFUSED, $event->getResult());

	}//end testAnUnknownChannelIsRefusedAndNothingIsIssued()

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
		return $this->getMockBuilder(WaitingAccountInvitation::class)->disableOriginalConstructor()->onlyMethods(['issue', 'issueCode'])->getMock();
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
