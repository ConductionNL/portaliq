<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portaliq#795: every identity secret goes out by mail, inside a link, and
 * nowhere else. The mailer is the one place that happens: it builds the link
 * with the secret in the fragment, sends it to the address it belongs to, and
 * never writes the secret into a log line.
 *
 * The doubles are the real OCP mail interfaces and the real deep link
 * builder over a route table double, so a link that would 404 fails here.
 *
 * @spec openspec/specs/portal-ways-in/spec.md
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T01
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
 */
class PortalIdentityMailerTest extends TestCase {

	/**
	 * What the mail carried: the button's link and every text line.
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

	public function testTheReferenceLinkIsMailedWithTheSecretInTheFragment(): void {
		$mailer = $this->mailer();

		$sent = $mailer->send(
			template: PortalIdentityMailer::TEMPLATE_REFERENCE_LINK,
			email: 'anna@example.nl',
			secret: 'secret-abc',
			organisation: 'gemeente-x',
			portal: ['slug' => 'gemeente-x', 'title' => 'Gemeente X', 'locales' => ['nl', 'en']]
		);

		$this->assertTrue($sent);
		$this->assertSame(1, $this->mailed['sent']);
		$this->assertSame(['anna@example.nl'], $this->mailed['to']);
		$this->assertSame(
			['https://portal.example.test/apps/portaliq/site?portal=gemeente-x#reference=secret-abc'],
			$this->mailed['buttons']
		);
		$this->assertStringContainsString('Gemeente X', $this->mailed['subject']);
		// The portal's first locale is its language.
		$this->assertSame(['nl'], array_values(array_unique($this->mailed['languages'])));

	}//end testTheReferenceLinkIsMailedWithTheSecretInTheFragment()

	public function testEachTemplateHasItsOwnFragment(): void {
		$expected = [
			PortalIdentityMailer::TEMPLATE_REFERENCE_LINK => '#reference=secret-abc',
			PortalIdentityMailer::TEMPLATE_INVITATION => '#invitation=secret-abc',
			PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION => '#confirm-email=secret-abc',
			// identity-ways-in-screens T01: the activation link of a self-registration.
			PortalIdentityMailer::TEMPLATE_REGISTRATION_ACTIVATION => '#activate=secret-abc',
		];

		foreach ($expected as $template => $fragment) {
			$this->setUp();
			$this->mailer()->send(
				template: $template,
				email: 'anna@example.nl',
				secret: 'secret-abc',
				organisation: 'gemeente-x',
				portal: ['slug' => 'gemeente-x', 'title' => 'Gemeente X']
			);

			$this->assertStringEndsWith($fragment, (string)($this->mailed['buttons'][0] ?? ''), $template);
		}

	}//end testEachTemplateHasItsOwnFragment()

	/**
	 * The three ways in open on the Vue site, where their screens are
	 * (identity-ways-in-screens, portaliq#1021), and so does the e-mail
	 * confirmation since the React portal retired (REQ-SRP-049).
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	public function testTheWaysInOpenOnTheSite(): void {
		$expected = [
			PortalIdentityMailer::TEMPLATE_REFERENCE_LINK => '/apps/portaliq/site?portal=gemeente-x#',
			PortalIdentityMailer::TEMPLATE_INVITATION => '/apps/portaliq/site?portal=gemeente-x#',
			PortalIdentityMailer::TEMPLATE_REGISTRATION_ACTIVATION => '/apps/portaliq/site?portal=gemeente-x#',
			PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION => '/apps/portaliq/site?portal=gemeente-x#',
		];

		foreach ($expected as $template => $address) {
			$this->setUp();
			$this->mailer()->send(template: $template, email: 'anna@example.nl', secret: 's', organisation: 'gemeente-x', portal: ['slug' => 'gemeente-x']);
			$this->assertStringContainsString($address, (string)($this->mailed['buttons'][0] ?? ''), $template);
		}

	}//end testTheWaysInOpenOnTheSite()

	public function testWithoutAPortalTheOrganisationsOnePortalIsUsed(): void {
		$mailer = $this->mailer(byOrganisation: ['slug' => 'gemeente-x-portaal', 'title' => 'Mijn Gemeente X']);

		$mailer->send(template: PortalIdentityMailer::TEMPLATE_INVITATION, email: 'piet@leverancier.nl', secret: 'secret-abc', organisation: 'gemeente-x');

		$this->assertSame(
			['https://portal.example.test/apps/portaliq/site?portal=gemeente-x-portaal#invitation=secret-abc'],
			$this->mailed['buttons']
		);
		$this->assertStringContainsString('Mijn Gemeente X', $this->mailed['subject']);

	}//end testWithoutAPortalTheOrganisationsOnePortalIsUsed()

	public function testWithNoPortalAtAllTheLinkNamesTheOrganisation(): void {
		$mailer = $this->mailer(byOrganisation: null);

		$mailer->send(template: PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION, email: 'nieuw@example.org', secret: 'secret-abc', organisation: 'gemeente-x');

		$this->assertSame(
			['https://portal.example.test/apps/portaliq/site?org=gemeente-x#confirm-email=secret-abc'],
			$this->mailed['buttons']
		);
		$this->assertStringContainsString('Organisatie X', $this->mailed['subject']);

	}//end testWithNoPortalAtAllTheLinkNamesTheOrganisation()

	public function testAFailedSendAnswersFalseAndNeverLogsTheSecret(): void {
		$mailer = $this->mailer(failWith: new RuntimeException('SMTP refused the message'));

		$sent = $mailer->send(
			template: PortalIdentityMailer::TEMPLATE_REFERENCE_LINK,
			email: 'anna@example.nl',
			secret: 'secret-abc',
			organisation: 'gemeente-x',
			portal: ['slug' => 'gemeente-x', 'title' => 'Gemeente X']
		);

		$this->assertFalse($sent);
		$this->assertNotSame([], $this->logged, 'a failed identity mail is logged');
		foreach ($this->logged as $line) {
			$this->assertStringNotContainsString('secret-abc', $line);
			$this->assertStringNotContainsString('anna@example.nl', $line);
		}

	}//end testAFailedSendAnswersFalseAndNeverLogsTheSecret()

	public function testNothingIsSentForAnUnknownTemplateABadAddressOrNoSecret(): void {
		$mailer = $this->mailer();

		$this->assertFalse($mailer->send(template: 'password-reset', email: 'anna@example.nl', secret: 'secret-abc', organisation: 'gemeente-x'));
		$this->assertFalse($mailer->send(template: PortalIdentityMailer::TEMPLATE_INVITATION, email: 'not an address', secret: 'secret-abc', organisation: 'gemeente-x'));
		$this->assertFalse($mailer->send(template: PortalIdentityMailer::TEMPLATE_INVITATION, email: 'anna@example.nl', secret: '', organisation: 'gemeente-x'));
		$this->assertSame(0, $this->mailed['sent']);

	}//end testNothingIsSentForAnUnknownTemplateABadAddressOrNoSecret()

	/**
	 * The mailer over the real deep link builder and doubles of the OCP mail
	 * interfaces that record what the mail carried.
	 *
	 * @param array<string, mixed>|null $byOrganisation What resolveByOrganisation() answers.
	 * @param \Throwable|null $failWith What IMailer::send() throws, if anything.
	 *
	 * @return PortalIdentityMailer
	 */
	private function mailer(?array $byOrganisation = null, ?\Throwable $failWith = null): PortalIdentityMailer {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->mailed['subject'] = $subject;
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

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static function (string $route, array $parameters): string {
			$query = '';
			if ($parameters !== []) {
				$query = '?' . http_build_query($parameters);
			}

			$path = '/apps/portaliq/portal';
			if ($route === 'portaliq.portalPage.site') {
				$path = '/apps/portaliq/site';
			}

			return $path . $query;
		});
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://portal.example.test' . $path);

		$portals = $this->getMockBuilder(PortalResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveByOrganisation'])
			->getMock();
		$portals->method('resolveByOrganisation')->willReturn($byOrganisation);

		$organisations = $this->getMockBuilder(PortalOrganisationConfigService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolve'])
			->getMock();
		$organisations->method('resolve')->willReturn(['organisationName' => 'Organisatie X']);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []): void {
				$this->logged[] = (string)$message . ' ' . json_encode($context);
			});
		}

		return new PortalIdentityMailer(
			mailer: $imailer,
			l10nFactory: $factory,
			deepLinks: new PortalDeepLinkBuilder($urls),
			portals: $portals,
			organisations: $organisations,
			logger: $logger
		);
	}//end mailer()

}//end class
