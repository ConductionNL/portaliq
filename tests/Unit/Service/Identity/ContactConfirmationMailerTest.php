<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\ContactConfirmationMailer;
use OCA\Portaliq\Service\PortalAccountService;
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
 * contact-page-question-form-and-not-found T02: a resident who sent a question
 * gets "We have received your question", naming the subject and never the
 * question text, at their confirmed address and nowhere else.
 *
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */
class ContactConfirmationMailerTest extends TestCase {

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

	private const QUESTION = 'Mijn grijze container is al drie weken niet geleegd, wat moet ik doen?';

	/**
	 * The action as the contribution declares it.
	 *
	 * @return array<string, mixed>
	 */
	private function action(): array {
		return [
			'id' => 'ask-question',
			'type' => 'create',
			'fields' => ['onderwerp', 'vraag'],
			'confirmationMail' => 'contact-confirmation',
			'topicField' => 'onderwerp',
			'fieldConfigs' => ['onderwerp' => ['valueLabels' => ['afval' => 'Afval']]],
		];
	}//end action()

	/**
	 * @return array<string, mixed>
	 */
	private function subject(): array {
		return ['subjectRef' => 'sub-1', 'organisation' => 'gemeente-x'];
	}//end subject()

	public function testTheMailNamesTheSubjectAndNeverTheQuestion(): void {
		$sent = $this->mailer()->afterCreate($this->subject(), $this->action(), ['onderwerp' => 'afval', 'vraag' => self::QUESTION]);

		$this->assertTrue($sent);
		$this->assertSame(['anna@example.nl'], $this->mailed['to']);
		$this->assertSame('We have received your question', $this->mailed['subject']);
		$everything = implode(' ', $this->mailed['texts']) . ' ' . $this->mailed['subject'] . ' ' . implode(' ', $this->mailed['buttons']);
		$this->assertStringContainsString('Afval', $everything);
		$this->assertStringNotContainsString('container', $everything);
		$this->assertStringNotContainsString(self::QUESTION, $everything);
		$this->assertSame(['https://portal.example.test/apps/portaliq/site?portal=gemeente-x'], $this->mailed['buttons']);
	}//end testTheMailNamesTheSubjectAndNeverTheQuestion()

	public function testAnActionThatAsksForNoMailSendsNone(): void {
		$action = $this->action();
		unset($action['confirmationMail']);

		$this->assertFalse($this->mailer()->afterCreate($this->subject(), $action, ['onderwerp' => 'afval']));
		$this->assertSame(0, $this->mailed['sent']);
	}//end testAnActionThatAsksForNoMailSendsNone()

	public function testAnAccountWithoutAConfirmedAddressGetsNothing(): void {
		$this->assertFalse($this->mailer(account: ['email' => ''])->afterCreate($this->subject(), $this->action(), ['onderwerp' => 'afval']));
		$this->assertFalse($this->mailer(account: null)->afterCreate($this->subject(), $this->action(), ['onderwerp' => 'afval']));
		$this->assertSame(0, $this->mailed['sent']);
	}//end testAnAccountWithoutAConfirmedAddressGetsNothing()

	public function testAFailingMailServerNeverBreaksTheCreate(): void {
		$sent = $this->mailer(failWith: new RuntimeException('smtp down'))->afterCreate($this->subject(), $this->action(), ['onderwerp' => 'afval']);

		$this->assertFalse($sent);
		$this->assertNotSame([], $this->logged);
		$this->assertStringNotContainsString('smtp down', implode(' ', $this->logged));
	}//end testAFailingMailServerNeverBreaksTheCreate()

	private function mailer(?array $account = ['email' => 'anna@example.nl'], ?\Throwable $failWith = null): ContactConfirmationMailer {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject): void {
			$this->mailed['subject'] = $subject;
		});
		$template->method('addHeading')->willReturnCallback(function (string $text): void {
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
		$portals->method('resolveByOrganisation')->willReturn(['slug' => 'gemeente-x', 'title' => 'Gemeente X', 'locales' => ['nl']]);

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

		$accounts = $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef'])
			->getMock();
		$accounts->method('findBySubjectRef')->willReturn($account);

		return new ContactConfirmationMailer(
			mailer: $imailer,
			l10nFactory: $factory,
			deepLinks: new PortalDeepLinkBuilder($urls),
			portals: $portals,
			organisations: $organisations,
			accounts: $accounts,
			logger: $logger
		);
	}//end mailer()

}//end class
