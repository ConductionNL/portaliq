<?php

/**
 * The mail a participant gets when a shared plan is near its end date
 * (shared-plans-with-a-caseworker, REQ-SPL-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\Plans\PlanEndingMailer;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PlanEndingMailer::class)]
#[UsesClass(MailTemplateRenderer::class)]
class PlanEndingMailerTest extends TestCase {
	/**
	 * What the mail held and where it went.
	 *
	 * @var array<string, mixed>
	 */
	private array $mailed = [];

	/**
	 * The mail log's records.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $logged = [];

	protected function setUp(): void {
		$this->mailed = ['to' => [], 'subject' => '', 'texts' => [], 'buttons' => [], 'sent' => 0];
		$this->logged = [];
	}//end setUp()

	/**
	 * Sanne gets the plan, its end date in words and the open actions, in the portal's name.
	 *
	 * @return void
	 */
	public function testTheParticipantIsMailedThePlanItsEndAndTheOpenActions(): void {
		$sent = $this->mailer()->send('sanne', 'org-1', 'Schuldhulp op orde', '2026-10-20', 3);

		$this->assertTrue($sent);
		$this->assertSame(['sanne@example.nl'], $this->mailed['to']);
		$this->assertSame('The plan Schuldhulp op orde ends on 20 October 2026', $this->mailed['subject']);
		$this->assertSame(['Your plan is nearly ending', 'The plan "Schuldhulp op orde" ends on 20 October 2026. 3 actions are still open. Extend the plan with your caseworker, or complete the actions.'], $this->mailed['texts']);
		$this->assertSame(['https://portal.example.test/apps/portaliq/site/zuiddrecht'], $this->mailed['buttons']);
		$this->assertSame([['portal' => 'zuiddrecht', 'templateKey' => 'plan-ending', 'email' => 'sanne@example.nl', 'status' => 'delivered', 'subjectRef' => 'sanne']], $this->logged);
	}//end testTheParticipantIsMailedThePlanItsEndAndTheOpenActions()

	/**
	 * A participant without a usable address gets no mail, and nothing is logged.
	 *
	 * @return void
	 */
	public function testNoAddressNoMail(): void {
		$this->assertFalse($this->mailer(null)->send('mark', 'org-1', 'Schuldhulp op orde', '2026-10-20', 3));
		$this->assertFalse($this->mailer(['email' => 'not an address'])->send('mark', 'org-1', 'Schuldhulp op orde', '2026-10-20', 3));
		$this->assertSame(0, $this->mailed['sent']);
		$this->assertSame([], $this->logged);
	}//end testNoAddressNoMail()

	/**
	 * A mail server that throws is a false, never an error in the daily job.
	 *
	 * @return void
	 */
	public function testAFailingMailServerIsAFalse(): void {
		$this->assertFalse($this->mailer(['email' => 'sanne@example.nl'], new RuntimeException('smtp down'))->send('sanne', 'org-1', 'Schuldhulp op orde', '2026-10-20', 3));
	}//end testAFailingMailServerIsAFalse()

	/**
	 * The mailer over doubles that record what was sent.
	 *
	 * @param array<string, string>|null $account   The participant's account.
	 * @param \Throwable|null            $failWith What the mail server throws.
	 *
	 * @return PlanEndingMailer
	 */
	private function mailer(?array $account = ['email' => 'sanne@example.nl'], ?\Throwable $failWith = null): PlanEndingMailer {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->mailed['subject'] = $subject;
		});
		$template->method('addHeading')->willReturnCallback(function (string $text): void {
			$this->mailed['texts'][] = $text;
		});
		$template->method('addBodyText')->willReturnCallback(function (string $text): void {
			$this->mailed['texts'][] = $text;
		});
		$template->method('addBodyButton')->willReturnCallback(function (string $text, string $url): void {
			$this->mailed['buttons'][] = $url;
		});

		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message): IMessage {
			$this->mailed['to'] = $to;
			return $message;
		});
		$message->method('useTemplate')->willReturnSelf();

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
		$factory->method('get')->willReturnCallback(function (): IL10N {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
			$l10n->method('l')->willReturnCallback(static fn (string $type, $data): string => date('j F Y', (int)$data));
			return $l10n;
		});

		$links = $this->createMock(PortalDeepLinkBuilder::class);
		$links->method('forSite')->willReturnCallback(static fn (string $portalSlug): string => 'https://portal.example.test/apps/portaliq/site/'.$portalSlug);

		$site = $this->createMock(MailSite::class);
		$site->method('portal')->willReturn(['slug' => 'zuiddrecht', 'title' => 'Gemeente Zuiddrecht', 'locales' => ['nl']]);
		$site->method('name')->willReturn('Gemeente Zuiddrecht');
		$site->method('language')->willReturn('nl');

		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturn($account);

		$reader = $this->createMock(\OCA\Portaliq\Service\PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);

		$log = $this->createMock(MailLog::class);
		$log->method('record')->willReturnCallback(function (string $portal, string $templateKey, string $email, string $status, string $caseRef = '', string $subjectRef = ''): bool {
			$this->logged[] = ['portal' => $portal, 'templateKey' => $templateKey, 'email' => $email, 'status' => $status, 'subjectRef' => $subjectRef];
			return true;
		});

		return new PlanEndingMailer($imailer, $factory, $links, $site, $accounts, new MailTemplateRenderer($reader), $log, $this->createMock(LoggerInterface::class));
	}//end mailer()
}//end class
