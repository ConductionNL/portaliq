<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormEmailCodeMailer;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The mail with the code that checks a form's e-mail address: in the site's
 * first language (Dutch by default), through the portal's own template when
 * there is one, and a false, never an exception, when it cannot be sent.
 */
#[CoversClass(FormEmailCodeMailer::class)]
class FormEmailCodeMailerTest extends TestCase {

	/**
	 * What the mail carried, in order.
	 *
	 * @var array<string, mixed>
	 */
	private array $mailed = [];

	/**
	 * The languages asked of the l10n factory.
	 *
	 * @var list<string>
	 */
	private array $languages = [];

	/**
	 * What reached the logger.
	 *
	 * @var list<array{string, array<string, mixed>}>
	 */
	private array $logged = [];

	protected function setUp(): void {
		$this->mailed = ['template' => '', 'subject' => '', 'parts' => [], 'to' => []];
		$this->languages = [];
		$this->logged = [];
	}//end setUp()

	/**
	 * The mailer under test.
	 *
	 * @param array<int, string>        $failedRecipients What the transport reports as failed.
	 * @param \Throwable|null           $failWith         Thrown by the transport.
	 * @param MailTemplateRenderer|null $renderer         The portal's template renderer.
	 *
	 * @return FormEmailCodeMailer
	 */
	private function mailer(array $failedRecipients = [], ?\Throwable $failWith = null, ?MailTemplateRenderer $renderer = null): FormEmailCodeMailer {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->mailed['subject'] = $subject;
		});
		$template->method('addHeader')->willReturnCallback(function (): void {
			$this->mailed['parts'][] = 'header';
		});
		$template->method('addHeading')->willReturnCallback(function (string $title): void {
			$this->mailed['parts'][] = 'heading: ' . $title;
		});
		$template->method('addBodyText')->willReturnCallback(function (string $text): void {
			$this->mailed['parts'][] = 'text: ' . $text;
		});
		$template->method('addFooter')->willReturnCallback(function (): void {
			$this->mailed['parts'][] = 'footer';
		});

		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message): IMessage {
			$this->mailed['to'] = $to;
			return $message;
		});
		$message->expects($this->any())->method('useTemplate')->with($template);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createEMailTemplate')->willReturnCallback(function (string $id) use ($template): IEMailTemplate {
			$this->mailed['template'] = $id;
			return $template;
		});
		$mailer->method('createMessage')->willReturn($message);
		if ($failWith !== null) {
			$mailer->method('send')->willThrowException($failWith);
		} else {
			$mailer->method('send')->with($message)->willReturn($failedRecipients);
		}

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $language = null): IL10N {
			$this->languages[] = $app . ':' . $language;
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(
				static fn (string $text, $parameters = []): string => '[' . $language . '] ' . vsprintf($text, (array)$parameters)
			);
			return $l10n;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});

		return new FormEmailCodeMailer($mailer, $factory, $logger, $renderer);
	}//end mailer()

	public function testTheCodeIsMailedInTheSitesFirstLanguage(): void {
		$sent = $this->mailer()->send('sanne@example.nl', '482913', ['locales' => ['en', 'nl']]);

		$this->assertTrue($sent);
		$this->assertSame(['portaliq:en'], $this->languages);
		$this->assertSame(['sanne@example.nl'], $this->mailed['to']);
		$this->assertSame('portaliq.form.emailcode', $this->mailed['template']);
		$this->assertSame('[en] Your code to check your e-mail address', $this->mailed['subject']);
		$this->assertSame(
			[
				'header',
				'heading: [en] Check your e-mail address',
				'text: [en] Your code is 482913. It works for 15 minutes.',
				'text: [en] Did you not ask for this code? Then you can ignore this mail.',
				'footer',
			],
			$this->mailed['parts']
		);
	}//end testTheCodeIsMailedInTheSitesFirstLanguage()

	public function testWithoutAUsableFirstLanguageTheMailIsDutch(): void {
		foreach ([[], ['locales' => []], ['locales' => ['']], ['locales' => [5]]] as $site) {
			$this->languages = [];

			$this->mailer()->send('a@example.nl', '1', $site);

			$this->assertSame(['portaliq:nl'], $this->languages, json_encode($site));
		}
	}//end testWithoutAUsableFirstLanguageTheMailIsDutch()

	public function testASingleLocaleStringCountsAsTheFirstLanguage(): void {
		$this->mailer()->send('a@example.nl', '1', ['locales' => 'de']);

		$this->assertSame(['portaliq:de'], $this->languages);
	}//end testASingleLocaleStringCountsAsTheFirstLanguage()

	public function testThePortalsOwnTemplateSuppliesSubjectAndBody(): void {
		$renderer = $this->createMock(MailTemplateRenderer::class);
		$renderer->expects($this->once())->method('render')->with(
			'gemeente',
			'form-email-code',
			['portal' => 'Gemeente Zuid', 'code' => '7731'],
			'[nl] Your code to check your e-mail address',
			'[nl] Your code is 7731. It works for 15 minutes.'
		)->willReturn(['subject' => 'Uw code', 'body' => 'Code 7731, geldig 15 minuten.']);

		$this->mailer(renderer: $renderer)->send('a@example.nl', '7731', ['slug' => ' gemeente ', 'title' => ' Gemeente Zuid ']);

		$this->assertSame('Uw code', $this->mailed['subject']);
		$this->assertContains('text: Code 7731, geldig 15 minuten.', $this->mailed['parts']);
		$this->assertNotContains('text: [nl] Your code is 7731. It works for 15 minutes.', $this->mailed['parts']);
	}//end testThePortalsOwnTemplateSuppliesSubjectAndBody()

	public function testAFailedRecipientIsNotSent(): void {
		$this->assertFalse($this->mailer(failedRecipients: ['a@example.nl'])->send('a@example.nl', '1', []));
	}//end testAFailedRecipientIsNotSent()

	public function testATransportFailureIsAFalseThatNamesOnlyTheExceptionClass(): void {
		$sent = $this->mailer(failWith: new RuntimeException('smtp down for a@example.nl code 1234'))->send('a@example.nl', '1234', []);

		$this->assertFalse($sent);
		$this->assertSame(
			[['Portaliq: form e-mail code not sent', ['exception' => RuntimeException::class, 'app' => 'portaliq']]],
			$this->logged
		);
	}//end testATransportFailureIsAFalseThatNamesOnlyTheExceptionClass()
}//end class
