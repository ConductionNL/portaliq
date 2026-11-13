<?php

/**
 * Portaliq Form Email Code Mailer Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * resident-identity-in-forms T03: the code goes to the address in the
 * portal's language, a portal's own text replaces the default one, and a
 * refusing mail server is a false that never quotes the transport.
 *
 * @covers \OCA\Portaliq\Service\Intake\FormEmailCodeMailer
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
class FormEmailCodeMailerTest extends TestCase {

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
		$this->mailed = ['to' => [], 'texts' => [], 'headings' => [], 'subject' => '', 'template' => '', 'languages' => [], 'header' => 0, 'footer' => 0];
		$this->logged = [];
	}//end setUp()

	public function testTheCodeGoesToTheAddressInTheDefaultText(): void {
		$sent = $this->mailer()->send('sanne@example.nl', '482915', ['locales' => ['en']]);

		$this->assertTrue($sent);
		$this->assertSame(['sanne@example.nl'], $this->mailed['to']);
		$this->assertSame('portaliq.form.emailcode', $this->mailed['template']);
		$this->assertSame('Your code to check your e-mail address', $this->mailed['subject']);
		$this->assertSame(['Check your e-mail address'], $this->mailed['headings']);
		$this->assertSame(
			['Your code is 482915. It works for 15 minutes.', 'Did you not ask for this code? Then you can ignore this mail.'],
			$this->mailed['texts']
		);
		$this->assertSame(['en'], $this->mailed['languages']);
		$this->assertSame(1, $this->mailed['header']);
		$this->assertSame(1, $this->mailed['footer']);
		$this->assertSame([], $this->logged);
	}//end testTheCodeGoesToTheAddressInTheDefaultText()

	public function testAPortalWithoutAUsableFirstLocaleGetsDutch(): void {
		$this->mailer()->send('a@example.nl', '1', []);
		$this->mailer()->send('a@example.nl', '1', ['locales' => ['']]);
		$this->mailer()->send('a@example.nl', '1', ['locales' => [7, 'de']]);
		$this->mailer()->send('a@example.nl', '1', ['locales' => 'fr']);

		$this->assertSame(['nl', 'nl', 'nl', 'fr'], $this->mailed['languages']);
	}//end testAPortalWithoutAUsableFirstLocaleGetsDutch()

	public function testAPortalsOwnTextReplacesTheDefaultWithTheTrimmedPortalAndTheCode(): void {
		$renderer = $this->createMock(MailTemplateRenderer::class);
		$renderer->expects($this->once())
			->method('render')
			->with(
				'gemeente',
				'form-email-code',
				['portal' => 'Gemeente Voorbeeld', 'code' => '482915'],
				'Your code to check your e-mail address',
				'Your code is 482915. It works for 15 minutes.'
			)
			->willReturn(['subject' => 'Uw code', 'body' => 'Code: 482915']);

		$sent = $this->mailer(renderer: $renderer)->send('sanne@example.nl', '482915', ['slug' => ' gemeente ', 'title' => ' Gemeente Voorbeeld ']);

		$this->assertTrue($sent);
		$this->assertSame('Uw code', $this->mailed['subject']);
		$this->assertSame('Code: 482915', $this->mailed['texts'][0]);
	}//end testAPortalsOwnTextReplacesTheDefaultWithTheTrimmedPortalAndTheCode()

	public function testAMailServerThatReportsAFailedRecipientIsAFalse(): void {
		$this->assertFalse($this->mailer(failedRecipients: ['sanne@example.nl'])->send('sanne@example.nl', '1', []));
	}//end testAMailServerThatReportsAFailedRecipientIsAFalse()

	public function testARefusingMailServerIsAFalseAndTheTransportIsNotQuoted(): void {
		$sent = $this->mailer(failWith: new RuntimeException('smtp down for sanne@example.nl'))->send('sanne@example.nl', '482915', []);

		$this->assertFalse($sent);
		$this->assertCount(1, $this->logged);
		$this->assertStringContainsString('Portaliq: form e-mail code not sent', $this->logged[0]);
		$this->assertStringContainsString('RuntimeException', $this->logged[0]);
		$this->assertStringNotContainsString('smtp down', $this->logged[0]);
		$this->assertStringNotContainsString('sanne@example.nl', $this->logged[0]);
		$this->assertStringNotContainsString('482915', $this->logged[0]);
	}//end testARefusingMailServerIsAFalseAndTheTransportIsNotQuoted()

	/**
	 * The mailer under test, with a recording mail server.
	 *
	 * @param \Throwable|null $failWith What the mail server throws.
	 * @param array<int, string> $failedRecipients What the mail server reports as failed.
	 * @param MailTemplateRenderer|null $renderer A portal's own text.
	 *
	 * @return FormEmailCodeMailer
	 */
	private function mailer(?\Throwable $failWith = null, array $failedRecipients = [], ?MailTemplateRenderer $renderer = null): FormEmailCodeMailer {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->mailed['subject'] = $subject;
		});
		$template->method('addHeader')->willReturnCallback(function (): void {
			$this->mailed['header']++;
		});
		$template->method('addFooter')->willReturnCallback(function (): void {
			$this->mailed['footer']++;
		});
		$template->method('addHeading')->willReturnCallback(function (string $text): void {
			$this->mailed['headings'][] = $text;
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

		$imailer = $this->createMock(IMailer::class);
		$imailer->method('createEMailTemplate')->willReturnCallback(function (string $id) use ($template): IEMailTemplate {
			$this->mailed['template'] = $id;
			return $template;
		});
		$imailer->method('createMessage')->willReturn($message);
		$imailer->method('send')->willReturnCallback(static function () use ($failWith, $failedRecipients): array {
			if ($failWith !== null) {
				throw $failWith;
			}

			return $failedRecipients;
		});

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $lang = null): IL10N {
			$this->assertSame('portaliq', $app);
			$this->mailed['languages'][] = (string)$lang;
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
			return $l10n;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message, array $context = []): void {
			$this->logged[] = (string)$message . ' ' . json_encode($context);
		});

		return new FormEmailCodeMailer(
			mailer: $imailer,
			l10nFactory: $factory,
			logger: $logger,
			renderer: $renderer
		);
	}//end mailer()
}//end class
