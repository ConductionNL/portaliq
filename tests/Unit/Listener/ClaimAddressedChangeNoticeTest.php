<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Listener\PortalRecordChangeListener;
use OCA\Portaliq\Service\Identity\PortalAccountsByClaim;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\Notifications\ChangeNoticeText;
use OCA\Portaliq\Service\Notifications\ClaimAddressedRecipients;
use OCA\Portaliq\Service\Notifications\PortalChangeRuleIndex;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalWriteContext;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A change rule that names its recipients by a claim reaches the portal
 * accounts whose claim the record holds, and only those that may read the
 * record (claim-addressed-change-notices).
 *
 * Built with OpenRegister's REAL ObjectUpdatedEvent and ObjectEntity, the
 * REAL index, account finder, recipient resolver and notice text. Only
 * OpenRegister's storage is faked: the account listing and the scoped read
 * of the record as each candidate. Outside a Nextcloud container, set
 * PORTALIQ_OPENREGISTER_LIB to an openregister checkout's lib/.
 *
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */
class ClaimAddressedChangeNoticeTest extends TestCase {

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
	 * Every scoped read of the record, as which account.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * Skip, and say why, when OpenRegister's classes are not loadable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(ObjectUpdatedEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not loadable: run inside Nextcloud or set PORTALIQ_OPENREGISTER_LIB.');
		}
	}//end setUp()

	/**
	 * The school app's bookings collection, reached through the child join.
	 *
	 * @return array<string, mixed>
	 */
	private function bookings(): array {
		return [
			'id' => 'parentConferenceSignups',
			'register' => 'learniq',
			'schema' => 'conference-signup',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'guardianRef',
			'via' => ['register' => 'learniq', 'schema' => 'learner-profile', 'scopeField' => 'guardianRefs', 'targetField' => 'id', 'match' => 'scopeField'],
			'label' => 'Your conference bookings',
			'fields' => ['lifecycle', 'learnerRef', 'slotLabel', 'teacherName', 'startsAt', 'declineNote'],
		];
	}//end bookings()

	/**
	 * The listener, wired as the app wires it, over faked storage.
	 *
	 * @param array<string, mixed> $rule The bookings rule; empty for the one learniq declares.
	 *
	 * @return PortalRecordChangeListener
	 */
	private function listener(array $rule = []): PortalRecordChangeListener {
		if ($rule === []) {
			$rule = [
				'ruleKey' => 'conference.answered',
				'collection' => 'parentConferenceSignups',
				'on' => ['field' => 'lifecycle', 'operator' => 'changed'],
				'titleField' => 'slotLabel',
				'recipients' => ['field' => 'guardianRef', 'claim' => 'guardianRef'],
				'messages' => [
					'acknowledged' => [
						'subject' => ['nl' => 'Gesprekstijd bevestigd', 'en' => 'Conference time confirmed'],
						'body' => ['nl' => 'De leerkracht heeft uw gesprekstijd bevestigd: {startsAt|datetime}, met {teacherName}.', 'en' => 'Confirmed: {startsAt|datetime}, with {teacherName}.'],
					],
					'declined' => [
						'subject' => ['nl' => 'Gesprekstijd afgezegd'],
						'body' => ['nl' => 'De leerkracht kan niet op {startsAt|datetime}. Toelichting: {declineNote}'],
					],
				],
			];
		}

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('servedAudiences')->willReturn(['parent']);
		$registry->method('aggregateFor')->willReturn(['contributions' => [
			['app' => 'learniq', 'audience' => 'parent', 'collections' => [$this->bookings()], 'notifications' => [$rule]],
		]]);

		$mapper = new class {
			/**
			 * The id-to-slug map.
			 *
			 * @return array<int, string>
			 */
			public function getIdToSlugMap(): array {
				return [7 => 'learniq', 21 => 'conference-signup'];
			}
		};
		$mappers = $this->createMock(ContainerInterface::class);
		$mappers->method('get')->willReturn($mapper);
		$index = new PortalChangeRuleIndex(registry: $registry, container: $mappers, logger: $this->createMock(LoggerInterface::class));

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$this->written[] = ['register' => $register, 'schema' => $schema, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];
				return ['uuid' => 'msg-1'];
			}
		);

		$dispatch = $this->createMock(NotificationDispatchService::class);
		$dispatch->method('dispatch')->willReturnCallback(
			function (string $ruleKey, string $appId, array $subject, array $record = []): void {
				$this->dispatched[] = ['ruleKey' => $ruleKey, 'appId' => $appId, 'subject' => $subject, 'record' => $record];
			}
		);

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $lang = null): IL10N {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => '['.(string)$lang.'] '.vsprintf($text, $params));
				return $l10n;
			}
		);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willReturn(['slug' => 'wilgenboom', 'locales' => ['nl']]);

		$timeZone = $this->createMock(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		// Any reference finds an account here, so a rule that fell back to
		// the record's own scope field (the child) WOULD write a message.
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static fn (string $subjectRef): array => ['subjectRef' => $subjectRef, 'organisation' => 'wilgenboom', 'audience' => 'parent']
		);

		return new PortalRecordChangeListener(
			rules: $index,
			writeContext: new PortalWriteContext(),
			accounts: $accounts,
			writer: $writer,
			dispatch: $dispatch,
			language: new PortalNoticeLanguage(l10nFactory: $factory, portals: $portals),
			logger: $this->createMock(LoggerInterface::class),
			messageBox: null,
			claimRecipients: new ClaimAddressedRecipients(accounts: $this->accountsByClaim(), reader: $this->reader()),
			noticeText: new ChangeNoticeText(timeZone: $timeZone),
		);
	}//end listener()

	/**
	 * The real account finder over an account listing with four accounts:
	 * Fatima (learniq guardian g-fatima), another family's guardian (g-other),
	 * an account holding the same value under ANOTHER app's claim, and a
	 * withdrawn account with Fatima's claim.
	 *
	 * @return PortalAccountsByClaim
	 */
	private function accountsByClaim(): PortalAccountsByClaim {
		$rows = [
			['subjectRef' => 'acct-fatima', 'audience' => 'parent', 'organisation' => 'wilgenboom', 'status' => 'active', 'claims' => ['learniq' => ['guardianRef' => 'g-fatima']]],
			['subjectRef' => 'acct-other', 'audience' => 'parent', 'organisation' => 'wilgenboom', 'status' => 'active', 'claims' => ['learniq' => ['guardianRef' => 'g-other']]],
			['subjectRef' => 'acct-pipelinq', 'audience' => 'client', 'organisation' => 'wilgenboom', 'status' => 'active', 'claims' => ['pipelinq' => ['guardianRef' => 'g-fatima']]],
			['subjectRef' => 'acct-void', 'audience' => 'parent', 'organisation' => 'wilgenboom', 'status' => 'void', 'claims' => ['learniq' => ['guardianRef' => 'g-fatima']]],
		];
		$objectService = new class ($rows) {
			/**
			 * The listing.
			 *
			 * @param array<int, array<string, mixed>> $rows The account rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Remember the register (ignored).
			 *
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}

			/**
			 * Remember the schema (ignored).
			 *
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
			}

			/**
			 * Every row, filter ignored: the finder must check them itself.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 500));
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new PortalAccountsByClaim(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end accountsByClaim()

	/**
	 * The scoped read: the booking is Fatima's child's, so only her account
	 * may read it; any other account gets null, as the via join answers.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $id, string $organisation = '', string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []): ?array {
				$this->reads[] = ['subjectRef' => $subjectRef, 'scopeClaim' => $scopeClaim, 'contributingApp' => $contributingApp, 'via' => $via, 'fields' => $fields, 'id' => $id];
				if ($subjectRef !== 'acct-fatima' || $id !== 'signup-1') {
					return null;
				}

				return ['lifecycle' => 'acknowledged', 'learnerRef' => 'child-1', 'teacherName' => 'J. de Vries', 'startsAt' => '2026-10-13T16:00:00+00:00', 'slotLabel' => '13-10-2026 18:00-18:15, J. de Vries', 'declineNote' => 'Ik ben ziek'];
			}
		);

		return $reader;
	}//end reader()

	/**
	 * A real OpenRegister booking.
	 *
	 * @param array<string, mixed> $data The booking.
	 *
	 * @return ObjectEntity
	 */
	private function booking(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('7');
		$entity->setSchema('21');
		$entity->setUuid('signup-1');
		$entity->setObject($data + ['learnerRef' => 'child-1', 'teacherName' => 'J. de Vries', 'startsAt' => '2026-10-13T16:00:00+00:00', 'slotLabel' => '13-10-2026 18:00-18:15, J. de Vries']);

		return $entity;
	}//end booking()

	/**
	 * The teacher acknowledges: Fatima reads the time and the teacher in her
	 * inbox, in Dutch; the other family's guardian, the other app's claim and
	 * the withdrawn account hear nothing.
	 *
	 * @return void
	 */
	public function testTheGuardianWhoseClaimTheRecordHoldsIsTold(): void {
		$old = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'booked']);
		$new = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'acknowledged']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertCount(1, $this->written, 'one message, to one account');
		$this->assertSame('acct-fatima', $this->written[0]['subjectRef']);
		$this->assertSame('wilgenboom', $this->written[0]['organisation']);
		$data = $this->written[0]['data'];
		$this->assertSame('Gesprekstijd bevestigd', $data['subject']);
		$this->assertSame('De leerkracht heeft uw gesprekstijd bevestigd: 13-10-2026 18:00, met J. de Vries.', $data['body']);
		$this->assertSame(['app' => 'learniq', 'collection' => 'parentConferenceSignups', 'id' => 'signup-1'], $data['recordLink']);
		$this->assertValidPortalMessage(message: $data + ['subjectRef' => 'acct-fatima']);

		$this->assertCount(1, $this->dispatched);
		$this->assertSame('conference.answered', $this->dispatched[0]['ruleKey']);
		$this->assertSame('learniq', $this->dispatched[0]['appId']);
		$this->assertSame(['subjectRef' => 'acct-fatima', 'organisation' => 'wilgenboom', 'audience' => 'parent', 'trust' => 'high'], $this->dispatched[0]['subject']);

		$this->assertSame(['acct-fatima'], array_column($this->reads, 'subjectRef'), 'only an account holding the claim is even read as');
		$this->assertSame('guardianRef', $this->reads[0]['scopeClaim']);
		$this->assertSame('learniq', $this->reads[0]['contributingApp']);
		$this->assertSame($this->bookings()['via'], $this->reads[0]['via']);
		$this->assertSame($this->bookings()['fields'], $this->reads[0]['fields']);
	}//end testTheGuardianWhoseClaimTheRecordHoldsIsTold()

	/**
	 * Another family's booking: the other guardian holds the claim, but the
	 * scoped read refuses the record (in this fake, only Fatima may read
	 * signup-1), so the other guardian hears nothing either.
	 *
	 * @return void
	 */
	public function testAnAccountThatMayNotReadTheRecordIsNotTold(): void {
		$old = $this->booking(data: ['guardianRef' => 'g-other', 'lifecycle' => 'booked']);
		$new = $this->booking(data: ['guardianRef' => 'g-other', 'lifecycle' => 'acknowledged']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame(['acct-other'], array_column($this->reads, 'subjectRef'));
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testAnAccountThatMayNotReadTheRecordIsNotTold()

	/**
	 * A booking without a guardian claim value: no message, and no account is
	 * looked for.
	 *
	 * @return void
	 */
	public function testNoClaimValueNoMessage(): void {
		$old = $this->booking(data: ['lifecycle' => 'booked']);
		$new = $this->booking(data: ['lifecycle' => 'acknowledged']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame([], $this->reads);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testNoClaimValueNoMessage()

	/**
	 * Declined: the teacher's note is in the message.
	 *
	 * @return void
	 */
	public function testADeclineCarriesTheTeachersNote(): void {
		$old = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'booked']);
		$new = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'declined', 'declineNote' => 'Ik ben ziek']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertCount(1, $this->written);
		$this->assertSame('Gesprekstijd afgezegd', $this->written[0]['data']['subject']);
		$this->assertSame('De leerkracht kan niet op 13-10-2026 18:00. Toelichting: Ik ben ziek', $this->written[0]['data']['body']);
	}//end testADeclineCarriesTheTeachersNote()

	/**
	 * A move to a value the rule writes no message for (the parent cancelled)
	 * is not reported.
	 *
	 * @return void
	 */
	public function testAValueWithoutAMessageIsNotReported(): void {
		$old = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'acknowledged']);
		$new = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'cancelled']);

		$this->listener()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testAValueWithoutAMessageIsNotReported()

	/**
	 * The same rule without `recipients` is dropped by the normaliser for a
	 * via collection, so nothing is reported: claim addressing is what lets it
	 * through.
	 *
	 * @return void
	 */
	public function testWithoutRecipientsAViaRuleStaysSilent(): void {
		$old = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'booked']);
		$new = $this->booking(data: ['guardianRef' => 'g-fatima', 'lifecycle' => 'acknowledged']);

		// The registry here hands the rule over unnormalised; the index must
		// still refuse to act on a via rule that names no recipients.
		$this->listener(rule: ['ruleKey' => 'conference.answered', 'collection' => 'parentConferenceSignups', 'on' => ['field' => 'lifecycle', 'operator' => 'changed']])
			->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->dispatched);
	}//end testWithoutRecipientsAViaRuleStaysSilent()

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
	}//end assertValidPortalMessage()
}//end class
