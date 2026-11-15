<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormConfirmationMailer;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * form-statements-intro-and-confirmation-mail T05: the mail carries the
 * reference and the summary to the form's own address; a refusal is a false,
 * never an exception, and never quotes the transport.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
 */
class FormConfirmationMailerTest extends TestCase {

	/**
	 * What the mail carried.
	 *
	 * @var array<string, mixed>
	 */
	private array $mailed = [];

	/**
	 * Everything that reached the logger.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	protected function setUp(): void {
		$this->mailed = ['to' => [], 'buttons' => [], 'texts' => [], 'subject' => '', 'sent' => 0, 'languages' => []];
		$this->logged = [];
	}//end setUp()

	public function testTheMailGoesToTheFormsAddressWithTheReferenceAndTheSummary(): void {
		$sent = $this->mailer()->send('sanne@example.nl', ['locales' => ['nl']], 'WOO-2026-4F7Q2D', 'Woo-verzoek', [['label' => 'Naam', 'value' => 'Sanne']]);

		$this->assertTrue($sent);
		$this->assertSame(['sanne@example.nl'], $this->mailed['to']);
		$this->assertSame('We have received your request', $this->mailed['subject']);
		$text = implode(' ', $this->mailed['texts']);
		$this->assertStringContainsString('WOO-2026-4F7Q2D', $text);
		$this->assertStringContainsString('Naam: Sanne', $text);
		$this->assertSame(['nl'], $this->mailed['languages']);
	}//end testTheMailGoesToTheFormsAddressWithTheReferenceAndTheSummary()

	/**
	 * submit-creates-the-case-directly 2.4: the mail names when the request
	 * arrived, when its term starts and when a decision is due, from the
	 * moments the destination answered; a moment that is not a date is left out.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#2.4
	 */
	public function testTheMailNamesTheReceivedMomentTheTermAndTheDeadline(): void {
		$this->mailer()->send('sanne@example.nl', ['locales' => ['nl']], '2026-0042', 'Bezwaar', [], ['receivedAt' => '2026-10-11T09:00:00+00:00', 'termStartsAt' => '2026-10-13T09:00:00+02:00', 'deadline' => '2026-11-24', 'identifier' => '2026-0042']);

		$this->assertContains('Received on 11-10-2026.', $this->mailed['texts']);
		$this->assertContains('The term starts on 13-10-2026.', $this->mailed['texts']);
		$this->assertContains('You can expect a decision by 24-11-2026.', $this->mailed['texts']);

		$this->setUp();
		$this->mailer()->send('sanne@example.nl', [], 'R', 'F', [], ['deadline' => 'not a date']);
		$this->assertStringNotContainsString('decision', implode(' ', $this->mailed['texts']));
	}//end testTheMailNamesTheReceivedMomentTheTermAndTheDeadline()

	public function testTheAddressIsTheFirstValidEmailAnswer(): void {
		$fields = [['name' => 'a', 'type' => 'string'], ['name' => 'b', 'type' => 'email'], ['name' => 'c', 'type' => 'email']];

		$this->assertSame('c@example.nl', $this->mailer()->addressIn($fields, ['a' => 'x@example.nl', 'b' => 'geen-adres', 'c' => ' c@example.nl ']));
		$this->assertSame('', $this->mailer()->addressIn($fields, ['b' => 'geen-adres']));
		$this->assertSame('', $this->mailer()->addressIn($fields, []));
	}//end testTheAddressIsTheFirstValidEmailAnswer()

	public function testARefusingMailServerIsAFalseAndTheTransportIsNotQuoted(): void {
		$sent = $this->mailer(failWith: new RuntimeException('smtp down for sanne@example.nl'))->send('sanne@example.nl', [], 'R', 'F', []);

		$this->assertFalse($sent);
		$this->assertNotSame([], $this->logged);
		$this->assertStringNotContainsString('smtp down', implode(' ', $this->logged));
		$this->assertStringNotContainsString('sanne@example.nl', implode(' ', $this->logged));
	}//end testARefusingMailServerIsAFalseAndTheTransportIsNotQuoted()

	/**
	 * mail-templates-admin-screen T02: a portal's own text replaces the
	 * default subject and body, with the trimmed portal title, the reference
	 * and the form's name as its variables.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
	 */
	public function testAPortalsOwnTextReplacesTheDefaultSubjectAndBody(): void {
		$renderer = $this->createMock(MailTemplateRenderer::class);
		$renderer->expects($this->once())
			->method('render')
			->with(
				'gemeente',
				'form-confirmation',
				['portal' => 'Gemeente Voorbeeld', 'reference' => 'R-7', 'formName' => 'Woo-verzoek'],
				'We have received your request',
				'Your request Woo-verzoek has been received under reference R-7.'
			)
			->willReturn(['subject' => 'Ontvangen', 'body' => 'Wij hebben R-7 ontvangen.']);

		$sent = $this->mailer(renderer: $renderer)->send('sanne@example.nl', ['slug' => ' gemeente ', 'title' => ' Gemeente Voorbeeld '], 'R-7', 'Woo-verzoek', []);

		$this->assertTrue($sent);
		$this->assertSame('Ontvangen', $this->mailed['subject']);
		$this->assertContains('Wij hebben R-7 ontvangen.', $this->mailed['texts']);
		$this->assertNotContains('Your request Woo-verzoek has been received under reference R-7.', $this->mailed['texts']);
	}//end testAPortalsOwnTextReplacesTheDefaultSubjectAndBody()

	/**
	 * mail-templates-admin-screen T03: each send is logged against the
	 * portal as delivered or failed, and a log that fails never fails the mail.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	public function testEachSendIsLoggedAsDeliveredOrFailed(): void {
		$records = [];
		$log = $this->createMock(MailLog::class);
		$log->method('record')->willReturnCallback(static function (...$arguments) use (&$records): bool {
			$records[] = array_slice(array_values($arguments), 0, 5);
			return true;
		});

		$this->assertTrue($this->mailer(mailLog: $log)->send('sanne@example.nl', ['slug' => ' gemeente '], 'R-1', 'F', []));
		$this->assertFalse($this->mailer(failWith: new RuntimeException('down'), mailLog: $log)->send('piet@example.nl', ['slug' => 'dorp'], 'R-2', 'F', []));

		$this->assertSame([
			['gemeente', 'form-confirmation', 'sanne@example.nl', 'delivered', 'R-1'],
			['dorp', 'form-confirmation', 'piet@example.nl', 'failed', 'R-2'],
		], $records);
	}//end testEachSendIsLoggedAsDeliveredOrFailed()

	public function testALogThatFailsDoesNotFailTheMail(): void {
		$log = $this->createMock(MailLog::class);
		$log->expects($this->once())->method('record')->willThrowException(new RuntimeException('register down'));

		$this->assertTrue($this->mailer(mailLog: $log)->send('sanne@example.nl', [], 'R', 'F', []));
		$this->assertSame(1, $this->mailed['sent']);
	}//end testALogThatFailsDoesNotFailTheMail()

	private function mailer(?\Throwable $failWith = null, ?MailTemplateRenderer $renderer = null, ?MailLog $mailLog = null): FormConfirmationMailer {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->mailed['subject'] = $subject;
		});
		$template->method("addHeading")->willReturnCallback(function (string $text): void {
			$this->mailed['texts'][] = $text;
		});
		$template->method('addBodyButton')->willReturnCallback(function (string $text, string $url): void {
			$this->mailed['buttons'][] = $url;
		});
		$template->method('addBodyText')->willReturnCallback(function (string $text): void {
			$this->mailed['texts'][] = $text;
		});

		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message): IMessage {
			$this->mailed['to'] = $to;
			return $message;
		});
		$message->method('useTemplate')->willReturnSelf();
		$message->method('setAutoSubmitted')->willReturnSelf();

		$imailer = $this->createMock(IMailer::class);
		$imailer->method('validateMailAddress')->willReturnCallback(static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
		$imailer->method('createEMailTemplate')->willReturn($template);
		$imailer->method('createMessage')->willReturn($message);
		$imailer->method('send')->willReturnCallback(function () use ($failWith): array {
			if ($failWith !== null) {
				throw $failWith;
			}

			$this->mailed['sent']++;
			return [];
		});

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $lang = null): IL10N {
			$this->mailed['languages'][] = (string)$lang;
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
			return $l10n;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []): void {
				$this->logged[] = (string)$message . ' ' . json_encode($context);
			});
		}

		return new FormConfirmationMailer(
			mailer: $imailer,
			l10nFactory: $factory,
			logger: $logger,
			renderer: $renderer,
			mailLog: $mailLog
		);
	}//end mailer()

}//end class
