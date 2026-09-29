<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Listener\PortalRecordChangeListener;
use OCA\Portaliq\BackgroundJob\MessageBoxDispatchJob;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\Notifications\MessageBoxChannel;
use OCA\Portaliq\Service\Notifications\PortalChangeRuleIndex;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalWriteContext;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\BackgroundJob\IJobList;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A declared change reaches the resident's inbox (REQ-NAP-002 to 004).
 *
 * Built with OpenRegister's REAL ObjectUpdatedEvent, ObjectCreatedEvent and
 * ObjectEntity (learniq#984: a faked event hid a wrong accessor, and every
 * object update on the instance 500ed). Outside a Nextcloud container, set
 * PORTALIQ_OPENREGISTER_LIB to an openregister checkout's lib/.
 *
 * The inbox message it writes is validated against the REAL portalMessage
 * schema in lib/Settings/portaliq_register.json.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
class PortalRecordChangeListenerTest extends TestCase {

	/**
	 * What the writer was asked to create.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * What was dispatched.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $dispatched = [];

	/**
	 * The jobs queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $jobs = [];

	/**
	 * The write context the listener shares with the writers.
	 *
	 * @var PortalWriteContext
	 */
	private PortalWriteContext $context;

	/**
	 * Skip, and say why, when OpenRegister's classes are not loadable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(ObjectUpdatedEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not loadable: run inside Nextcloud or set PORTALIQ_OPENREGISTER_LIB.');
		}

		$this->context = new PortalWriteContext();
	}//end setUp()

	/**
	 * The listener over a real index whose registry declares a dossiq case
	 * collection with a status rule and an inbox collection.
	 *
	 * @param bool $dispatchThrows Whether the dispatch fails.
	 *
	 * @return PortalRecordChangeListener
	 */
	private function listener(bool $dispatchThrows = false, array $messageBox = []): PortalRecordChangeListener {
		$inbox = ['id' => 'berichten', 'register' => 'zaken', 'schema' => 'bericht', 'scopeField' => 'ontvanger', 'kind' => 'inbox', 'label' => 'Berichten'];
		if (($messageBox['declared'] ?? false) === true) {
			$inbox['messageBox'] = ['recipientProvider' => 'messageBoxRecipient', 'bodyField' => 'inhoud'];
		}

		$notifications = ['message.created', ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'identifier']];
		if (($messageBox['nudge'] ?? true) === false) {
			$notifications = [$notifications[1]];
		}

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('servedAudiences')->willReturn(['client', 'guardian']);
		$registry->method('aggregateFor')->willReturn(['contributions' => [
			[
				'app' => 'dossiq',
				'audience' => 'client',
				'collections' => [
					['id' => 'mijnZaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'initiator', 'label' => 'Mijn zaken', 'fields' => ['identifier', 'status']],
					$inbox,
				],
				'notifications' => $notifications,
			],
		]]);

		$mapper = new class {
			/**
			 * The id-to-slug map.
			 *
			 * @return array<int, string>
			 */
			public function getIdToSlugMap(): array {
				return [5 => 'zaken', 12 => 'zaak', 13 => 'bericht', 1 => 'portaliq', 2 => 'portalMessage'];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$index = new PortalChangeRuleIndex(registry: $registry, container: $container, logger: $this->createMock(LoggerInterface::class));

		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static fn (string $subjectRef): ?array => ($subjectRef === 'bsn-1' ? ['subjectRef' => 'bsn-1', 'organisation' => 'venray', 'audience' => 'client', 'notificationPreferences' => ($messageBox['preferences'] ?? null)] : null)
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$this->written[] = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'data' => $data];
				return ['uuid' => 'msg-1'];
			}
		);

		$dispatch = $this->createMock(NotificationDispatchService::class);
		$dispatch->method('dispatch')->willReturnCallback(
			function (string $ruleKey, string $appId, array $subject, array $record = []) use ($dispatchThrows): void {
				if ($dispatchThrows === true) {
					throw new RuntimeException('queue down');
				}

				$this->dispatched[] = ['ruleKey' => $ruleKey, 'appId' => $appId, 'subject' => $subject, 'record' => $record];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new PortalRecordChangeListener(
			rules: $index,
			writeContext: $this->context,
			accounts: $accounts,
			writer: $writer,
			dispatch: $dispatch,
			l10nFactory: $factory,
			logger: $this->createMock(LoggerInterface::class),
			messageBox: $this->messageBoxChannel(offered: ($messageBox['offered'] ?? false)),
		);
	}//end listener()

	/**
	 * The REAL message box channel over an organisation that offers it or not,
	 * and a job list that records what was queued.
	 *
	 * @param bool $offered Whether the organisation offers the channel.
	 *
	 * @return MessageBoxChannel
	 */
	private function messageBoxChannel(bool $offered): MessageBoxChannel {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('messageBox')->willReturnCallback(
			static fn (string $orgSlug): ?array => ($offered === true && $orgSlug === 'venray' ? ['sourceId' => 'berichtenbox-venray', 'label' => 'MijnOverheid Berichtenbox'] : null)
		);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function (string $job, mixed $argument = null): void {
				$this->jobs[] = ['job' => $job, 'argument' => $argument];
			}
		);

		return new MessageBoxChannel(orgConfig: $orgConfig, jobList: $jobs, logger: $this->createMock(LoggerInterface::class));
	}//end messageBoxChannel()

	/**
	 * A real OpenRegister object.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('5');
		$entity->setSchema($schema);
		$entity->setUuid('zaak-uuid-1');
		$entity->setObject($data);

		return $entity;
	}//end object()

	/**
	 * Validate a written message against the real portalMessage schema.
	 *
	 * @param array<string, mixed> $message The message data plus its stamped scope field.
	 *
	 * @return void
	 */
	private function assertValidPortalMessage(array $message): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/portaliq_register.json'), true);
		$schema = $register['components']['schemas']['portalMessage'];
		$jsonSchema = json_decode(
			(string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]),
			false
		);
		$result = (new Validator())->validate(json_decode((string)json_encode($message), false), $jsonSchema);
		$this->assertTrue($result->isValid(), 'the message fits the register schema');
		$this->assertArrayHasKey('recordLink', $schema['properties'], 'the schema declares recordLink');
	}//end assertValidPortalMessage()

	/**
	 * A handler moves the case to another status: a message and a dispatch.
	 *
	 * @return void
	 */
	public function testStatusChangeWritesAMessageAndDispatches(): void {
		$old = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'identifier' => 'Z-2026-1', 'status' => 'Ontvangen']);
		$new = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'identifier' => 'Z-2026-1', 'status' => 'Afgewezen']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertCount(1, $this->written);
		$data = $this->written[0]['data'];
		$this->assertSame(['app' => 'dossiq', 'collection' => 'mijnZaken', 'id' => 'zaak-uuid-1'], $data['recordLink']);
		$this->assertSame(false, $data['read']);
		$this->assertStringContainsString('Z-2026-1 has been updated', $data['subject']);
		$this->assertStringNotContainsString('Afgewezen', $data['subject'].$data['body'], 'no field value but the title');
		$this->assertValidPortalMessage(message: $data + ['subjectRef' => 'bsn-1']);

		$this->assertCount(1, $this->dispatched);
		$this->assertSame('case.updated', $this->dispatched[0]['ruleKey']);
		$this->assertSame('dossiq', $this->dispatched[0]['appId']);
		$this->assertSame('bsn-1', $this->dispatched[0]['subject']['subjectRef']);
		$this->assertSame(['app' => 'dossiq', 'collection' => 'mijnZaken', 'id' => 'zaak-uuid-1', 'label' => 'Mijn zaken'], $this->dispatched[0]['record']);
	}//end testStatusChangeWritesAMessageAndDispatches()

	/**
	 * Only an internal note changes: nothing.
	 *
	 * @return void
	 */
	public function testUnchangedFieldDoesNothing(): void {
		$old = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Ontvangen', 'note' => 'a']);
		$new = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Ontvangen', 'note' => 'b']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testUnchangedFieldDoesNothing()

	/**
	 * No old record: a change it cannot see is not reported.
	 *
	 * @return void
	 */
	public function testMissingOldObjectDoesNothing(): void {
		$new = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Afgewezen']);

		$this->listener()->handle(new ObjectUpdatedEvent($new));

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testMissingOldObjectDoesNothing()

	/**
	 * A failing dispatch never reaches OpenRegister's save.
	 *
	 * @return void
	 */
	public function testFailureNeverThrows(): void {
		$old = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Ontvangen']);
		$new = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Afgewezen']);

		$this->listener(dispatchThrows: true)->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertCount(1, $this->written, 'the message was written before the dispatch failed');
	}//end testFailureNeverThrows()

	/**
	 * The resident's own correction through portaliq is not reported to them.
	 *
	 * @return void
	 */
	public function testResidentsOwnWriteIsNotReported(): void {
		$old = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Ontvangen']);
		$new = $this->object(schema: '12', data: ['initiator' => 'bsn-1', 'status' => 'Aangevuld']);
		$listener = $this->listener();

		$this->context->run(write: static fn () => $listener->handle(new ObjectUpdatedEvent($new, $old)));

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
		$this->assertFalse($this->context->isActive(), 'the context is left after the write');
	}//end testResidentsOwnWriteIsNotReported()

	/**
	 * A handler writes into the case app's inbox collection: one e-mail nudge.
	 *
	 * @return void
	 */
	public function testCaseAppMessageDispatches(): void {
		$message = $this->object(schema: '13', data: ['ontvanger' => 'bsn-1', 'onderwerp' => 'Uw aanvraag']);

		$this->listener()->handle(new ObjectCreatedEvent($message));

		$this->assertSame([], $this->written, 'the case app wrote the message itself');
		$this->assertCount(1, $this->dispatched);
		$this->assertSame('message.created', $this->dispatched[0]['ruleKey']);
		$this->assertSame('dossiq', $this->dispatched[0]['appId']);
	}//end testCaseAppMessageDispatches()

	/**
	 * Portaliq's own portalMessage is dispatched by its writer, not twice.
	 *
	 * @return void
	 */
	public function testPortalMessageIsNotDispatchedTwice(): void {
		$message = new ObjectEntity();
		$message->setRegister('1');
		$message->setSchema('2');
		$message->setUuid('msg-1');
		$message->setObject(['subjectRef' => 'bsn-1', 'subject' => 'x']);

		$this->listener()->handle(new ObjectCreatedEvent($message));

		$this->assertSame([], $this->dispatched);
	}//end testPortalMessageIsNotDispatchedTwice()

	/**
	 * A new message in an inbox collection that names a recipient method gets
	 * a message box job only when the organisation offers the channel and the
	 * resident did not switch it off (inbox-berichtenbox-channel, REQ-MBC-003,
	 * REQ-MBC-005). The job names the message and the method; it carries no
	 * recipient, because portaliq has none until the job asks the case app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function testMessageBoxJobOnlyWhenAllowed(): void {
		$message = $this->object(schema: '13', data: ['ontvanger' => 'bsn-1', 'onderwerp' => 'Besluit op uw aanvraag']);

		$this->listener(messageBox: ['declared' => true, 'offered' => true])->handle(new ObjectCreatedEvent($message));
		$this->assertCount(1, $this->jobs, 'offered, declared and not switched off: one job');
		$this->assertSame(MessageBoxDispatchJob::class, $this->jobs[0]['job']);
		$argument = $this->jobs[0]['argument'];
		$this->assertSame('messageBox', $argument['channel']);
		$this->assertSame('messageBoxRecipient', $argument['recipientProvider']);
		$this->assertSame(['app' => 'dossiq', 'collection' => 'berichten', 'id' => 'zaak-uuid-1', 'label' => 'Berichten'], $argument['record']);
		$this->assertSame(['register' => 'zaken', 'schema' => 'bericht', 'scopeField' => 'ontvanger'], $argument['source']);
		$this->assertSame(['body' => 'inhoud', 'subject' => ''], $argument['letterFields'], 'the declared letter fields travel with the job');
		$this->assertSame('bsn-1', $argument['subjectRef']);
		$this->assertSame('venray', $argument['organisation']);
		$this->assertCount(1, $this->dispatched, 'the e-mail nudge still goes as before');

		foreach ([
			'the organisation does not offer it' => ['declared' => true, 'offered' => false],
			'the resident switched it off' => ['declared' => true, 'offered' => true, 'preferences' => ['messageBox' => ['enabled' => false]]],
			'the collection names no recipient method' => ['declared' => false, 'offered' => true],
		] as $why => $case) {
			$this->jobs = [];
			$this->listener(messageBox: $case)->handle(new ObjectCreatedEvent($message));
			$this->assertSame([], $this->jobs, 'no job when '.$why);
		}

		// The channel does not depend on the e-mail rule: an app that declares
		// no `message.created` still gets its letters to the message box.
		$this->jobs = [];
		$this->dispatched = [];
		$this->listener(messageBox: ['declared' => true, 'offered' => true, 'nudge' => false])->handle(new ObjectCreatedEvent($message));
		$this->assertCount(1, $this->jobs, 'the message box job without the e-mail rule');
		$this->assertSame([], $this->dispatched, 'and no e-mail nudge the app did not ask for');
	}//end testMessageBoxJobOnlyWhenAllowed()
}//end class
