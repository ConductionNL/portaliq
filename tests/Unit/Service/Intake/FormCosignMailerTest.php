<?php

/**
 * Portaliq Form Cosign Mailer Test
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

use OCA\Portaliq\Service\Intake\FormCosignMailer;
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
 * resident-identity-in-forms T04: the co-signer's invitation carries the
 * code, the deadline day and the form, the submitter hears why a request was
 * not co-signed, a portal's own text replaces the default, and a refusing mail
 * server is a false that never quotes the transport.
 *
 * @covers \OCA\Portaliq\Service\Intake\FormCosignMailer
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class FormCosignMailerTest extends TestCase {

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

	public function testTheInvitationCarriesTheCodeTheDeadlineDayAndTheForm(): void {
		$sent = $this->mailer()->invite('henk@example.nl', '7RQ-K2M', '2026-10-22T10:00:00+00:00', 'Woo-verzoek', ['locales' => ['en']]);

		$this->assertTrue($sent);
		$this->assertSame(['henk@example.nl'], $this->mailed['to']);
		$this->assertSame('portaliq.form.cosigninvite', $this->mailed['template']);
		$this->assertSame('Would you co-sign a request?', $this->mailed['subject']);
		$this->assertSame(['Co-sign a request'], $this->mailed['headings']);
		$this->assertSame(
			[
				'You are asked to co-sign the request "Woo-verzoek". Your code is 7RQ-K2M. Do this before 2026-10-22.',
				'Go to the co-sign page of the portal, enter the code and sign in with DigiD. You can read the request before you decide.',
			],
			$this->mailed['texts']
		);
		$this->assertSame(['en'], $this->mailed['languages']);
		$this->assertSame([1, 1], [$this->mailed['header'], $this->mailed['footer']]);
		$this->assertSame([], $this->logged);
	}//end testTheInvitationCarriesTheCodeTheDeadlineDayAndTheForm()

	public function testAnUnreadableDeadlineIsShownAsGiven(): void {
		$this->mailer()->invite('henk@example.nl', '7RQ-K2M', 'soon', 'Woo-verzoek', []);

		$this->assertStringEndsWith('Do this before soon.', $this->mailed['texts'][0]);
		$this->assertSame(['nl'], $this->mailed['languages']);
	}//end testAnUnreadableDeadlineIsShownAsGiven()

	public function testARefusalAndALapseTellTheSubmitterWhy(): void {
		$this->assertTrue($this->mailer()->ended('sanne@example.nl', 'AANVRAAG-7', 'refused', []));
		$this->assertSame(['sanne@example.nl'], $this->mailed['to']);
		$this->assertSame('portaliq.form.cosignended', $this->mailed['template']);
		$this->assertSame('Your request was not co-signed', $this->mailed['subject']);
		$this->assertSame(['Not co-signed'], $this->mailed['headings']);
		$this->assertSame(
			['The co-signer refused your request AANVRAAG-7, so it was not sent.', 'You can send the request again with another co-signer.'],
			$this->mailed['texts']
		);

		$this->mailed['texts'] = [];
		$this->mailer()->ended('sanne@example.nl', 'AANVRAAG-8', 'expired', []);
		$this->assertSame('Your request AANVRAAG-8 was not co-signed before the deadline, so it was not sent.', $this->mailed['texts'][0]);
	}//end testARefusalAndALapseTellTheSubmitterWhy()

	public function testAPortalsOwnTextReplacesBothDefaults(): void {
		$renderer = $this->createMock(MailTemplateRenderer::class);
		$renderer->expects($this->exactly(2))
			->method('render')
			->willReturnCallback(function (string $portal, string $key, array $values, string $subject, string $body): array {
				$this->assertSame('gemeente', $portal);
				if ($key === 'form-cosign-invite') {
					$this->assertSame(['portal' => 'Gemeente Voorbeeld', 'code' => '7RQ-K2M', 'deadline' => '2026-10-22', 'formName' => 'Woo-verzoek'], $values);
					$this->assertSame('Would you co-sign a request?', $subject);
					return ['subject' => 'Meetekenen?', 'body' => 'Code 7RQ-K2M'];
				}

				$this->assertSame('form-cosign-ended', $key);
				$this->assertSame(['portal' => 'Gemeente Voorbeeld', 'reference' => 'AANVRAAG-7'], $values);
				return ['subject' => 'Niet meegetekend', 'body' => 'Helaas'];
			});
		$site = ['slug' => ' gemeente ', 'title' => ' Gemeente Voorbeeld '];

		$this->mailer(renderer: $renderer)->invite('henk@example.nl', '7RQ-K2M', '2026-10-22T10:00:00+00:00', 'Woo-verzoek', $site);
		$this->assertSame(['Meetekenen?', 'Code 7RQ-K2M'], [$this->mailed['subject'], $this->mailed['texts'][0]]);

		$this->mailer(renderer: $renderer)->ended('sanne@example.nl', 'AANVRAAG-7', 'refused', $site);
		$this->assertSame('Niet meegetekend', $this->mailed['subject']);
	}//end testAPortalsOwnTextReplacesBothDefaults()

	public function testAMailServerThatReportsAFailedRecipientIsAFalse(): void {
		$this->assertFalse($this->mailer(failedRecipients: ['henk@example.nl'])->invite('henk@example.nl', '7RQ-K2M', '2026-10-22', 'Woo', []));
	}//end testAMailServerThatReportsAFailedRecipientIsAFalse()

	public function testARefusingMailServerIsAFalseAndTheTransportIsNotQuoted(): void {
		$sent = $this->mailer(failWith: new RuntimeException('smtp down for sanne@example.nl'))->ended('sanne@example.nl', 'AANVRAAG-7', 'expired', []);

		$this->assertFalse($sent);
		$this->assertCount(1, $this->logged);
		$this->assertStringContainsString('Portaliq: co-sign mail not sent', $this->logged[0]);
		$this->assertStringContainsString('RuntimeException', $this->logged[0]);
		$this->assertStringNotContainsString('smtp down', $this->logged[0]);
		$this->assertStringNotContainsString('sanne@example.nl', $this->logged[0]);
	}//end testARefusingMailServerIsAFalseAndTheTransportIsNotQuoted()

	/**
	 * The mailer under test, with a recording mail server.
	 *
	 * @param \Throwable|null $failWith What the mail server throws.
	 * @param array<int, string> $failedRecipients What the mail server reports as failed.
	 * @param MailTemplateRenderer|null $renderer A portal's own text.
	 *
	 * @return FormCosignMailer
	 */
	private function mailer(?\Throwable $failWith = null, array $failedRecipients = [], ?MailTemplateRenderer $renderer = null): FormCosignMailer {
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

		return new FormCosignMailer(
			mailer: $imailer,
			l10nFactory: $factory,
			logger: $logger,
			renderer: $renderer
		);
	}//end mailer()
}//end class
