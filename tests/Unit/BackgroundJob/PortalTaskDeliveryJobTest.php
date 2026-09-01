<?php

/**
 * Tests for the portal task delivery worker.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\PortalTaskDeliveryJob;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * A configurable stand-in for one openregister PortalTaskDelivery row — the
 * worker consumes the entity duck-typed (no compile-time openregister
 * dependency), so the fake declares exactly the getters the seam publishes.
 */
class FakeDeliveryRow {
	/**
	 * Constructor.
	 *
	 * @param string $uuid The delivery uuid.
	 * @param string $party The frozen party reference.
	 * @param string $channel `portal-inbox` or `mail`.
	 * @param string $kind `ask`, `re-ask` or `reminder`.
	 * @param array<string, mixed> $message The engine's payload.
	 */
	public function __construct(
		private readonly string $uuid,
		private readonly string $party,
		private readonly string $channel,
		private readonly string $kind = 'ask',
		private readonly array $message = [],
	) {
	}//end __construct()

	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()

	public function getPartyReference(): string {
		return $this->party;
	}//end getPartyReference()

	public function getChannel(): string {
		return $this->channel;
	}//end getChannel()

	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	/**
	 * @return array<string, mixed>
	 */
	public function getMessage(): array {
		return $this->message;
	}//end getMessage()
}//end class

/**
 * A recording stand-in for openregister's PortalTaskDeliveryService seam.
 */
class FakeLedger {
	/**
	 * @var array<int, string>
	 */
	public array $delivered = [];

	/**
	 * @var array<string, string>
	 */
	public array $failed = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $rows The pending rows to hand out.
	 * @param bool $throwOnPending Whether pending() explodes.
	 * @param bool $throwOnMarkFailed Whether markFailed() explodes.
	 */
	public function __construct(
		private readonly array $rows = [],
		private readonly bool $throwOnPending = false,
		private readonly bool $throwOnMarkFailed = false,
	) {
	}//end __construct()

	/**
	 * @param int $limit Page size (ignored by the fake).
	 *
	 * @return array<int, mixed>
	 */
	public function pending(int $limit = 100): array {
		if ($this->throwOnPending === true) {
			throw new RuntimeException('ledger unreadable');
		}

		return $this->rows;
	}//end pending()

	public function markDelivered(string $uuid): void {
		$this->delivered[] = $uuid;
	}//end markDelivered()

	public function markFailed(string $uuid, string $error): void {
		if ($this->throwOnMarkFailed === true) {
			throw new RuntimeException('settle refused');
		}

		$this->failed[$uuid] = $error;
	}//end markFailed()
}//end class

/**
 * The worker's contract: absent openregister it does nothing; a
 * `portal-inbox` row becomes ONE scoped `portalMessage` carrying the deep
 * link and the idempotency key, then settles; an already-written message is
 * settled without a duplicate; a `mail` row goes through the account lookup
 * and the mailer, privacy-minimal; every failure marks the row failed with
 * its reason and NEVER blocks the remaining rows or escapes to cron.
 *
 * @covers \OCA\Portaliq\BackgroundJob\PortalTaskDeliveryJob
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */
class PortalTaskDeliveryJobTest extends TestCase {
	private const MESSAGE = [
		'taskUuid' => 'task-1',
		'title' => 'Stuur uw bewijsstuk',
		'description' => 'We hebben nog een document van u nodig.',
		'reason' => null,
		'dueAt' => '2026-09-15T12:00:00+00:00',
	];

	/**
	 * Without openregister the job logs at debug and touches nothing.
	 */
	public function testWithoutOpenregisterTheJobDoesNothing(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readCollection');
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$this->runJob(ledger: null, reader: $reader, writer: $writer);

		$this->addToAssertionCount(1);
	}//end testWithoutOpenregisterTheJobDoesNothing()

	/**
	 * A `portal-inbox` row becomes one message in the party's OWN scope
	 * (the `party:` prefix stripped), carrying the task uuid (deep link) and
	 * the ledger row uuid (idempotency key), and the row settles delivered.
	 */
	public function testAnInboxRowBecomesOneScopedMessageAndSettles(): void {
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'portal-inbox', kind: 'ask', message: self::MESSAGE)]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);

		$captured = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
				$captured = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'data' => $data];

				return ['id' => 'm-1'];
			}
		);

		$this->runJob(ledger: $ledger, reader: $reader, writer: $writer);

		$this->assertSame(['d-1'], $ledger->delivered);
		$this->assertSame([], $ledger->failed);
		$this->assertSame('portalMessage', $captured['schema']);
		$this->assertSame('subjectRef', $captured['scopeField']);
		$this->assertSame('s1', $captured['subjectRef']);
		$this->assertSame('task-1', $captured['data']['taskUuid']);
		$this->assertSame('d-1', $captured['data']['deliveryUuid']);
		$this->assertStringContainsString('Stuur uw bewijsstuk', $captured['data']['subject']);
		$this->assertFalse($captured['data']['read']);
	}//end testAnInboxRowBecomesOneScopedMessageAndSettles()

	/**
	 * A message already carrying the row's deliveryUuid (a crash between
	 * write and settle) is settled WITHOUT writing a duplicate.
	 */
	public function testAnExistingMessageSettlesWithoutADuplicate(): void {
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'portal-inbox', kind: 'ask', message: self::MESSAGE)]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([['id' => 'm-1', 'deliveryUuid' => 'd-1']]);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$this->runJob(ledger: $ledger, reader: $reader, writer: $writer);

		$this->assertSame(['d-1'], $ledger->delivered);
	}//end testAnExistingMessageSettlesWithoutADuplicate()

	/**
	 * A degraded (null) message write marks the row failed with the reason —
	 * the caseworker's delivery state reads failed, never silence.
	 */
	public function testAFailedWriteMarksTheRowFailed(): void {
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'portal-inbox', kind: 'ask', message: self::MESSAGE)]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturn(null);

		$this->runJob(ledger: $ledger, reader: $reader, writer: $writer);

		$this->assertSame([], $ledger->delivered);
		$this->assertSame('portal message write failed', $ledger->failed['d-1']);
	}//end testAFailedWriteMarksTheRowFailed()

	/**
	 * One row's Throwable is caught, marked failed, and the REMAINING rows
	 * still run — failure isolation, and nothing escapes to cron.
	 */
	public function testOneFailingRowNeverBlocksTheRest(): void {
		$ledger = new FakeLedger(
			rows: [
				new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'portal-inbox', kind: 'ask', message: self::MESSAGE),
				new FakeDeliveryRow(uuid: 'd-2', party: 'party:s2', channel: 'portal-inbox', kind: 'ask', message: self::MESSAGE),
				new FakeDeliveryRow(uuid: 'd-3', party: 'party:s3', channel: 'portal-inbox', kind: 'reminder', message: self::MESSAGE),
			]
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);

		$calls = 0;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function () use (&$calls) {
				++$calls;
				if ($calls === 1) {
					throw new RuntimeException('database exploded');
				}

				return ['id' => 'm-' . $calls];
			}
		);

		$this->runJob(ledger: $ledger, reader: $reader, writer: $writer);

		$this->assertSame(['d-2', 'd-3'], $ledger->delivered);
		$this->assertSame('database exploded', $ledger->failed['d-1']);
	}//end testOneFailingRowNeverBlocksTheRest()

	/**
	 * A party reference without the `party:` prefix is refused, not guessed at.
	 */
	public function testAnUnusablePartyReferenceIsAnHonestFailure(): void {
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 's1-without-prefix', channel: 'portal-inbox')]);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$this->runJob(ledger: $ledger, reader: $this->createMock(PortalObjectReader::class), writer: $writer);

		$this->assertStringContainsString('party reference', $ledger->failed['d-1']);
	}//end testAnUnusablePartyReferenceIsAnHonestFailure()

	/**
	 * A `mail` row without a resolvable portalAccount is an honest failure.
	 */
	public function testAMailRowWithoutAnAccountFails(): void {
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'mail')]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');

		$this->runJob(ledger: $ledger, reader: $reader, writer: $this->createMock(PortalObjectWriter::class), mailer: $mailer);

		$this->assertStringContainsString('portalAccount', $ledger->failed['d-1']);
	}//end testAMailRowWithoutAnAccountFails()

	/**
	 * A `mail` row sends ONE privacy-minimal mail — organisation name and
	 * portal link only, never the task title or description — and settles.
	 */
	public function testAMailRowSendsThePrivacyMinimalMail(): void {
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'mail', kind: 'ask', message: self::MESSAGE)]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([['id' => 'a-1', 'email' => 'resident@example.org', 'organisation' => 'org-1']]);

		$bodies = [];
		$message = $this->createMock(IMessage::class);
		$message->method('setSubject')->willReturnCallback(function (string $subject) use (&$bodies, $message) {
			$bodies['subject'] = $subject;

			return $message;
		});
		$message->method('setPlainBody')->willReturnCallback(function (string $body) use (&$bodies, $message) {
			$bodies['body'] = $body;

			return $message;
		});
		$message->method('setTo')->willReturnCallback(function (array $to) use (&$bodies, $message) {
			$bodies['to'] = $to;

			return $message;
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->willReturn([]);

		$this->runJob(ledger: $ledger, reader: $reader, writer: $this->createMock(PortalObjectWriter::class), mailer: $mailer);

		$this->assertSame(['d-1'], $ledger->delivered);
		$this->assertSame(['resident@example.org'], $bodies['to']);
		$this->assertStringContainsString('https://cloud.example/portal?org=org-1', $bodies['body']);
		// Privacy-minimal by construction: no task or case content in the mail.
		$this->assertStringNotContainsString('Stuur uw bewijsstuk', $bodies['subject'] . $bodies['body']);
		$this->assertStringNotContainsString('document van u nodig', $bodies['body']);
	}//end testAMailRowSendsThePrivacyMinimalMail()

	/**
	 * A re-ask carries its reason and the due date in the message body; a
	 * reminder gets the reminder subject; an unknown kind falls back to the
	 * ask wording rather than failing the row.
	 */
	public function testKindsReasonsAndDueDatesShapeTheMessage(): void {
		$message = self::MESSAGE;
		$message['reason'] = 'De foto was onleesbaar.';
		$ledger = new FakeLedger(
			rows: [
				new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'portal-inbox', kind: 're-ask', message: $message),
				new FakeDeliveryRow(uuid: 'd-2', party: 'party:s2', channel: 'portal-inbox', kind: 'reminder', message: self::MESSAGE),
				new FakeDeliveryRow(uuid: 'd-3', party: 'party:s3', channel: 'portal-inbox', kind: 'escalation', message: ['taskUuid' => 't', 'title' => 'X', 'dueAt' => 'not-a-date']),
			]
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);

		$written = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$written) {
				$written[] = $data;

				return ['id' => 'm'];
			}
		);

		$this->runJob(ledger: $ledger, reader: $reader, writer: $writer);

		$this->assertSame(['d-1', 'd-2', 'd-3'], $ledger->delivered);
		$this->assertStringContainsString('another look', $written[0]['subject']);
		$this->assertStringContainsString('De foto was onleesbaar.', $written[0]['body']);
		$this->assertStringContainsString('15-09-2026', $written[0]['body']);
		$this->assertStringContainsString('Reminder', $written[1]['subject']);
		// The unknown kind fell back to the ask wording, and the unparseable
		// due date simply renders no due line.
		$this->assertStringContainsString('new task', $written[2]['subject']);
		$this->assertStringNotContainsString('not-a-date', $written[2]['body']);
	}//end testKindsReasonsAndDueDatesShapeTheMessage()

	/**
	 * Mail failure modes are honest failures with their own reasons: an
	 * invalid address, a throwing mailer, and reported failed recipients.
	 */
	public function testMailFailureModesAreNamed(): void {
		$account = [['id' => 'a-1', 'email' => 'broken@@example', 'organisation' => '']];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn($account);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(false);
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'mail')]);
		$this->runJob(ledger: $ledger, reader: $reader, writer: $this->createMock(PortalObjectWriter::class), mailer: $mailer);
		$this->assertStringContainsString('valid mail address', $ledger->failed['d-1']);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([['id' => 'a-1', 'email' => 'ok@example.org', 'organisation' => '']]);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-2', party: 'party:s1', channel: 'mail')]);
		$this->runJob(ledger: $ledger, reader: $reader, writer: $this->createMock(PortalObjectWriter::class), mailer: $mailer);
		$this->assertStringContainsString('mail send failed', $ledger->failed['d-2']);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->method('send')->willReturn(['ok@example.org']);
		$ledger = new FakeLedger(rows: [new FakeDeliveryRow(uuid: 'd-3', party: 'party:s1', channel: 'mail')]);
		$this->runJob(ledger: $ledger, reader: $reader, writer: $this->createMock(PortalObjectWriter::class), mailer: $mailer);
		$this->assertStringContainsString('failed recipients', $ledger->failed['d-3']);
	}//end testMailFailureModesAreNamed()

	/**
	 * An unknown channel, a non-object pending row and an unreadable ledger
	 * are each absorbed: the unknown channel is an honest failure, the junk
	 * row is skipped, and a pending() explosion ends the run quietly.
	 */
	public function testJunkRowsAndAnUnreadableLedgerAreAbsorbed(): void {
		$ledger = new FakeLedger(
			rows: [
				'not-a-row',
				new FakeDeliveryRow(uuid: 'd-1', party: 'party:s1', channel: 'carrier-pigeon'),
			]
		);
		$this->runJob(ledger: $ledger, reader: $this->createMock(PortalObjectReader::class), writer: $this->createMock(PortalObjectWriter::class));
		$this->assertStringContainsString('unknown delivery channel', $ledger->failed['d-1']);

		$ledger = new FakeLedger(rows: [], throwOnPending: true);
		$this->runJob(ledger: $ledger, reader: $this->createMock(PortalObjectReader::class), writer: $this->createMock(PortalObjectWriter::class));
		$this->assertSame([], $ledger->delivered);
	}//end testJunkRowsAndAnUnreadableLedgerAreAbsorbed()

	/**
	 * Even the settle-as-failed call may explode; the job logs it and moves
	 * on — the row simply stays pending for the next run, and nothing ever
	 * reaches the cron runner.
	 */
	public function testASettleFailureIsAbsorbed(): void {
		$ledger = new FakeLedger(
			rows: [
				new FakeDeliveryRow(uuid: 'd-1', party: 'no-prefix', channel: 'portal-inbox'),
				new FakeDeliveryRow(uuid: 'd-2', party: 'party:s2', channel: 'portal-inbox', message: self::MESSAGE),
			],
			throwOnMarkFailed: true
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturn(['id' => 'm-1']);

		$this->runJob(ledger: $ledger, reader: $reader, writer: $writer);

		// Row 1's failure could not be settled (markFailed throws) yet row 2
		// was still processed and delivered.
		$this->assertSame(['d-2'], $ledger->delivered);
	}//end testASettleFailureIsAbsorbed()

	/**
	 * A container that answers with a non-object for the seam name reads as
	 * "openregister absent", exactly like a container miss.
	 */
	public function testANonObjectSeamServiceReadsAsAbsent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn('not-a-service');

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$job = new PortalTaskDeliveryJob(
			$this->timeFactory(),
			$container,
			$this->createMock(PortalObjectReader::class),
			$writer,
			$this->createMock(PortalOrganisationConfigService::class),
			$this->createMock(IMailer::class),
			$this->createMock(IFactory::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class)
		);
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);

		$this->addToAssertionCount(1);
	}//end testANonObjectSeamServiceReadsAsAbsent()

	/**
	 * A shared ITimeFactory mock.
	 */
	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-01T10:00:00+00:00'));

		return $time;
	}//end timeFactory()

	/**
	 * Build and run the job around a fake ledger (null = openregister absent).
	 *
	 * @param FakeLedger|null $ledger The ledger fake, or null.
	 * @param PortalObjectReader $reader The reader mock.
	 * @param PortalObjectWriter $writer The writer mock.
	 * @param IMailer|null $mailer The mailer mock (an inert default otherwise).
	 */
	private function runJob(?FakeLedger $ledger, PortalObjectReader $reader, PortalObjectWriter $writer, ?IMailer $mailer = null): void {
		$container = $this->createMock(ContainerInterface::class);
		if ($ledger === null) {
			$container->method('get')->willThrowException(
				new class ('no openregister') extends RuntimeException implements NotFoundExceptionInterface {
				}
			);
		} else {
			$container->method('get')->willReturn($ledger);
		}

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-01T10:00:00+00:00'));

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturnCallback(
			function (string $app, $lang = null) {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					static fn (string $text, $parameters = []) => '[' . $lang . '] ' . vsprintf($text, (array)$parameters)
				);

				return $l10n;
			}
		);

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('resolve')->willReturn(['organisationName' => 'Gemeente Test']);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path) => 'https://cloud.example' . $path
		);

		$job = new PortalTaskDeliveryJob(
			$time,
			$container,
			$reader,
			$writer,
			$orgConfig,
			$mailer ?? $this->createMock(IMailer::class),
			$l10nFactory,
			$urlGenerator,
			$this->createMock(LoggerInterface::class)
		);

		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()
}//end class
