<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Portaliq\BackgroundJob\MessageBoxDispatchJob;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Listener\PortalDigitalPostDeliveredListener;
use OCA\Portaliq\Service\Notifications\MessageBoxSender;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A message box job sends one inbox message to the resident's government
 * message box through integriq, and logs the attempt without the recipient
 * (inbox-berichtenbox-channel, REQ-MBC-002, REQ-MBC-003, REQ-MBC-004). The
 * sender is real; integriq's events are its REAL classes, from
 * PORTALIQ_INTEGRIQ_LIB or the verbatim copies in tests/Stubs.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
 */
class MessageBoxDispatchJobTest extends TestCase {

	/**
	 * A clock.
	 *
	 * @return ITimeFactory
	 */
	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);

		return $time;
	}//end timeFactory()

	/**
	 * A reader whose account store holds the given account.
	 *
	 * @param array<string, mixed>|null $account The account.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(?array $account): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef): array => (
				$schema === 'portalAccount' && $scopeField === 'subjectRef' && $subjectRef === 's1' && $account !== null ? [$account] : []
			)
		);

		return $reader;
	}//end reader()

	/**
	 * A writer capturing what it is asked to create and update.
	 *
	 * @param array<int, mixed> $created The rows created (by reference).
	 * @param array<int, mixed> $updated The rows updated (by reference).
	 *
	 * @return PortalObjectWriter
	 */
	private function writer(array &$created, array &$updated): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$created) {
				$created[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'data');
				return $data;
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$updated) {
				$updated[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'id', 'data');
				return $data;
			}
		);

		return $writer;
	}//end writer()

	/**
	 * Run the job.
	 *
	 * @param MessageBoxDispatchJob $job      The job.
	 * @param mixed                 $argument The argument.
	 *
	 * @return void
	 */
	private function invokeRun(MessageBoxDispatchJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end invokeRun()

	/**
	 * A message box job, as MessageBoxChannel queues it.
	 *
	 * @var array<string, mixed>
	 */
	private const MESSAGE_BOX_ARGUMENT = [
		'subjectRef' => 's1',
		'organisation' => 'org-1',
		'audience' => 'client',
		'appId' => 'dossiq',
		'ruleKey' => 'message.created',
		'channel' => 'messageBox',
		'recipientProvider' => 'messageBoxRecipient',
		'record' => ['app' => 'dossiq', 'collection' => 'berichten', 'id' => 'bericht-1', 'label' => 'Berichten'],
		'source' => ['register' => 'zaken', 'schema' => 'bericht', 'scopeField' => 'ontvanger'],
	];

	/**
	 * A citizen service number that must never leave the send.
	 *
	 * @var string
	 */
	private const BSN = '999993653';

	/**
	 * Every event the job dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $events = [];

	/**
	 * Every log call, whatever its level: [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The job over a REAL message box sender, over a case app provider whose
	 * recipient method answers `$recipient`, and an event dispatcher that
	 * hands the REAL integriq event to `$integriq`.
	 *
	 * @param array<int, mixed> $created   The rows written (by reference).
	 * @param array<int, mixed> $updated   The rows updated (by reference).
	 * @param string|null       $recipient What the case app answers.
	 * @param callable|null     $integriq  What integriq does with the event.
	 * @param string            $sendEvent The event class the sender looks for.
	 * @param array|null        $message   The message the resident's inbox reads, or the default.
	 *
	 * @return MessageBoxDispatchJob
	 */
	private function messageBoxJob(array &$created, array &$updated, ?string $recipient, ?callable $integriq = null, string $sendEvent = DigitalPostSendRequestedEvent::class, ?array $message = null): MessageBoxDispatchJob {
		$message ??= ['subject' => 'Besluit op uw aanvraag', 'body' => 'Uw aanvraag is toegekend.', 'attachments' => [['documentId' => 'doc-7']], 'ontvanger' => 's1'];
		$reader = $this->reader(account: ['@self' => ['id' => 'account-1'], 'subjectRef' => 's1']);
		$reader->method('readObject')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef, string $id): ?array => (
				$register === 'zaken' && $schema === 'bericht' && $scopeField === 'ontvanger' && $subjectRef === 's1' && $id === 'bericht-1'
				? $message
				: null
			)
		);

		$provider = new class ($recipient) {
			/**
			 * @param string|null $recipient What to answer.
			 */
			public function __construct(private ?string $recipient) {
			}

			/**
			 * The recipient for one message.
			 *
			 * @param string $messageId The message.
			 *
			 * @return string|null
			 */
			public function messageBoxRecipient(string $messageId): ?string {
				return ($messageId === 'bericht-1' ? $this->recipient : 'wrong-message');
			}
		};
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturnCallback(static fn (string $appId): ?object => ($appId === 'dossiq' ? $provider : null));

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('resolve')->willReturn(['organisationName' => 'Test Org']);
		$orgConfig->method('messageBox')->willReturn(['sourceId' => 'berichtenbox-test', 'label' => 'MijnOverheid Berichtenbox']);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($integriq): void {
				$this->events[] = $event;
				if ($integriq !== null) {
					$integriq($event);
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []): void {
					$this->logged[] = [(string)$message, $context];
				}
			);
		}

		$writer = $this->writer(created: $created, updated: $updated);
		$sender = new MessageBoxSender(
			orgConfig: $orgConfig,
			locator: $locator,
			reader: $reader,
			writer: $writer,
			events: $dispatcher,
			logger: $logger,
			sendEvent: $sendEvent,
		);

		return new MessageBoxDispatchJob(time: $this->timeFactory(), sender: $sender, logger: $logger);
	}//end messageBoxJob()

	/**
	 * The case app keeps a message in the portal only: its recipient method
	 * answers null, and nothing is sent, dispatched or logged
	 * (inbox-berichtenbox-channel, REQ-MBC-002).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
	 */
	public function testNullRecipientSendsNothing(): void {
		$created = [];
		$updated = [];
		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: null), self::MESSAGE_BOX_ARGUMENT);

		$this->assertSame([], $this->events, 'no send was requested');
		$this->assertSame([], $created, 'no row: nothing was attempted');

		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: '  '), self::MESSAGE_BOX_ARGUMENT);
		$this->assertSame([], $this->events, 'an empty answer is no recipient either');
	}//end testNullRecipientSendsNothing()

	/**
	 * Nobody handled the event, or integriq is not installed: the attempt is
	 * recorded as failed, never as sent (REQ-MBC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testUnhandledEventIsARefusal(): void {
		if (class_exists(DigitalPostSendRequestedEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}

		$created = [];
		$updated = [];
		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN), self::MESSAGE_BOX_ARGUMENT);
		$this->assertCount(1, $this->events, 'the send was requested');
		$this->assertCount(1, $created);
		$this->assertSame('messageBox', $created[0]['data']['channel']);
		$this->assertSame('failed', $created[0]['data']['status']);
		$this->assertSame('unhandled', $created[0]['data']['refusalCode']);
		$this->assertArrayNotHasKey('externalMessageId', $created[0]['data']);

		$refused = [];
		$this->invokeRun(
			$this->messageBoxJob(
				created: $refused,
				updated: $updated,
				recipient: self::BSN,
				integriq: static function (DigitalPostSendRequestedEvent $event): void {
					$event->setHandled(true);
					$event->setRefusal('The source is not configured.', 'unknown_source');
				}
			),
			self::MESSAGE_BOX_ARGUMENT
		);
		$this->assertSame('failed', $refused[0]['data']['status']);
		$this->assertSame('unknown_source', $refused[0]['data']['refusalCode']);

		$this->events = [];
		$absent = [];
		$this->invokeRun(
			$this->messageBoxJob(created: $absent, updated: $updated, recipient: self::BSN, sendEvent: 'OCA\\Integriq\\Event\\NotInstalledEvent'),
			self::MESSAGE_BOX_ARGUMENT
		);
		$this->assertSame([], $this->events, 'nothing to dispatch without integriq');
		$this->assertSame('failed', $absent[0]['data']['status']);
		$this->assertSame('not_installed', $absent[0]['data']['refusalCode']);
	}//end testUnhandledEventIsARefusal()

	/**
	 * dossiq keeps a portal letter's text in `content`, not `body` (dossiq
	 * portaalBericht 1.0.0, lib/Settings/register.d/50-zaakportaal.json on
	 * development: required caseId, senderRef, content). The letter that
	 * reaches integriq carries that text and its subject. Before this fix it
	 * went out with an empty body.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testDossiqsPortaalBerichtTextIsTheLetter(): void {
		if (class_exists(DigitalPostSendRequestedEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}

		// dossiq's own portaalBericht shape, every property its schema declares.
		$portaalBericht = [
			'caseId' => 'zaak-uuid-1',
			'caseReference' => 'Z-2026-0042',
			'senderType' => 'burger',
			'senderRef' => 'medewerker-7',
			'senderName' => 'Gemeente Venray',
			'recipientRef' => 's1',
			'subject' => 'Besluit op uw aanvraag',
			'content' => 'Uw aanvraag voor een dakkapel is toegekend.',
			'attachments' => [],
			'direction' => 'handler_to_citizen',
			'sentAt' => '2026-09-29T10:00:00+00:00',
		];

		$created = [];
		$updated = [];
		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN, message: $portaalBericht), self::MESSAGE_BOX_ARGUMENT);

		$this->assertCount(1, $this->events);
		$this->assertSame('Uw aanvraag voor een dakkapel is toegekend.', $this->events[0]->getBody());
		$this->assertSame('Besluit op uw aanvraag', $this->events[0]->getSubject());
	}//end testDossiqsPortaalBerichtTextIsTheLetter()

	/**
	 * A field the collection declares for the letter's text wins over the
	 * usual names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testTheDeclaredLetterFieldsAreRead(): void {
		if (class_exists(DigitalPostSendRequestedEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}

		$created = [];
		$updated = [];
		$argument = ['letterFields' => ['body' => 'tekst', 'subject' => 'kop']] + self::MESSAGE_BOX_ARGUMENT;
		$message = ['kop' => 'Uw vergunning', 'tekst' => 'De vergunning is verleend.', 'subject' => 'niet dit', 'body' => 'en niet dit', 'ontvanger' => 's1'];
		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN, message: $message), $argument);

		$this->assertSame('De vergunning is verleend.', $this->events[0]->getBody());
		$this->assertSame('Uw vergunning', $this->events[0]->getSubject());
	}//end testTheDeclaredLetterFieldsAreRead()

	/**
	 * A message with no text is never sent as an empty letter: nothing is
	 * dispatched, the attempt is recorded as refused with `empty_body`, and a
	 * warning names the message, not the resident.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testAnEmptyLetterIsNotSent(): void {
		$created = [];
		$updated = [];
		$this->invokeRun(
			$this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN, message: ['subject' => 'Besluit', 'content' => '   ', 'ontvanger' => 's1']),
			self::MESSAGE_BOX_ARGUMENT
		);

		$this->assertSame([], $this->events, 'no letter was asked for');
		$this->assertCount(1, $created);
		$this->assertSame('failed', $created[0]['data']['status']);
		$this->assertSame('empty_body', $created[0]['data']['refusalCode']);
		$this->assertStringContainsString('no text', (string)json_encode($this->logged));
		$this->assertStringNotContainsString(self::BSN, (string)json_encode([$created, $this->logged]));
	}//end testAnEmptyLetterIsNotSent()

	/**
	 * The recipient reaches integriq's event and nothing else: not the
	 * notification row, not a log line (REQ-MBC-002).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
	 */
	public function testRecipientIsInNoLogAndNoRow(): void {
		if (class_exists(DigitalPostSendRequestedEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}

		foreach ([
			'sent' => static function (DigitalPostSendRequestedEvent $event): void {
				$event->setHandled(true);
				$event->setMessageId('msg-1');
			},
			'refused' => static function (DigitalPostSendRequestedEvent $event): void {
				$event->setHandled(true);
				$event->setRefusal('Recipient '.$event->getRecipient().' has no message box.', 'no_box');
			},
			'unhandled' => null,
			'throws' => static function (): void {
				throw new \RuntimeException('integriq down for '.self::BSN);
			},
		] as $case => $integriq) {
			$created = [];
			$updated = [];
			$this->events = [];
			$this->logged = [];
			$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN, integriq: $integriq), self::MESSAGE_BOX_ARGUMENT);

			$this->assertCount(1, $this->events, $case);
			$this->assertSame(self::BSN, $this->events[0]->getRecipient(), 'integriq got the recipient');
			$this->assertCount(1, $created, $case.': one row');
			$this->assertStringNotContainsString(self::BSN, (string)json_encode([$created, $updated]), $case.': no row holds it');
			$this->assertStringNotContainsString(self::BSN, (string)json_encode($this->logged), $case.': no log line holds it');
		}
	}//end testRecipientIsInNoLogAndNoRow()

	/**
	 * A send integriq took is recorded as sent, with its message id and the
	 * message it is about; the event carried the organisation's source, the
	 * message's subject, body and attachments, and `requestedBy` portaliq
	 * (REQ-MBC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testMessageIdIsRecorded(): void {
		if (class_exists(DigitalPostSendRequestedEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}

		$created = [];
		$updated = [];
		$job = $this->messageBoxJob(
			created: $created,
			updated: $updated,
			recipient: self::BSN,
			integriq: static function (DigitalPostSendRequestedEvent $event): void {
				$event->setHandled(true);
				$event->setMessageId('msg-1');
			}
		);
		$this->invokeRun($job, self::MESSAGE_BOX_ARGUMENT);

		$event = $this->events[0];
		$this->assertSame('portaliq', $event->getSourceApp());
		$this->assertSame('berichtenbox-test', $event->getSourceId());
		$this->assertSame('Besluit op uw aanvraag', $event->getSubject());
		$this->assertSame('Uw aanvraag is toegekend.', $event->getBody());
		$this->assertSame([['documentId' => 'doc-7']], $event->getAttachments());
		$this->assertSame('portaliq', $event->getRequestedBy());

		$this->assertCount(1, $created);
		$row = $created[0];
		$this->assertSame('portalNotification', $row['schema']);
		$this->assertSame('accountRef', $row['scopeField']);
		$this->assertSame('account-1', $row['subjectRef']);
		$this->assertSame('messageBox', $row['data']['channel']);
		$this->assertSame('sent', $row['data']['status']);
		$this->assertSame('msg-1', $row['data']['externalMessageId']);
		$this->assertSame(['app' => 'dossiq', 'collection' => 'berichten', 'id' => 'bericht-1'], $row['data']['recordLink']);
		$this->assertSame(0, $row['data']['attempts']);

		$this->assertSame([], $updated, 'no e-mail streak or fallback flag is touched');
	}//end testMessageIdIsRecorded()

	/**
	 * Integriq announces the letter's first status inside the send itself,
	 * before portaliq has written the row (DigitalPostService::handleSendRequest
	 * calls announce() before it sets the message id). The REAL delivered
	 * listener hears it then, and the row the sender writes carries it: a
	 * simulated binding's letter is logged as simulated, not as sent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function testAStatusAnnouncedDuringTheSendLandsOnTheRow(): void {
		if (class_exists(DigitalPostSendRequestedEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}

		$listenerReader = $this->createMock(PortalObjectReader::class);
		$listenerReader->method('readCollection')->willReturn([]);
		$listener = new PortalDigitalPostDeliveredListener(
			reader: $listenerReader,
			writer: $this->createMock(PortalObjectWriter::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$created = [];
		$updated = [];
		$job = $this->messageBoxJob(
			created: $created,
			updated: $updated,
			recipient: self::BSN,
			integriq: static function (DigitalPostSendRequestedEvent $event) use ($listener): void {
				$event->setHandled(true);
				$listener->handle(new DigitalPostDeliveredEvent(messageId: 'msg-9', status: 'sent', requestedBy: $event->getRequestedBy(), previousStatus: 'queued', simulated: true));
				$event->setMessageId('msg-9');
			}
		);
		$this->invokeRun($job, self::MESSAGE_BOX_ARGUMENT);

		$this->assertSame('simulated', $created[0]['data']['status']);
		$this->assertSame('msg-9', $created[0]['data']['externalMessageId']);
	}//end testAStatusAnnouncedDuringTheSendLandsOnTheRow()

	/**
	 * A resident whose account is gone gets nothing sent and nothing logged.
	 *
	 * @return void
	 */
	public function testNoAccountSendsNothing(): void {
		$created = [];
		$updated = [];
		$argument = self::MESSAGE_BOX_ARGUMENT;
		$argument['subjectRef'] = 'gone';
		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN), $argument);
		$this->invokeRun($this->messageBoxJob(created: $created, updated: $updated, recipient: self::BSN), 'not an array');

		$this->assertSame([], $this->events);
		$this->assertSame([], $created);
	}//end testNoAccountSendsNothing()
}//end class
