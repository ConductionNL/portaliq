<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormConfirmationMailer;
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

	private function mailer(?\Throwable $failWith = null): FormConfirmationMailer {
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
			logger: $logger
		);
	}//end mailer()

}//end class
