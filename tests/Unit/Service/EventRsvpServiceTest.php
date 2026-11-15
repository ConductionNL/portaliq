<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\EventRsvpService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
class EventRsvpServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function feedReader(?array $event, bool $ownChild = true): EventFeedReader {
		$reader = $this->createMock(EventFeedReader::class);
		$reader->method('readOwnEvent')->willReturn($event);
		$reader->method('isOwnChild')->willReturn($ownChild);
		return $reader;
	}//end feedReader()

	public function testReturnsFalseWhenTheEventIsNotInAudienceOrRsvpDisabled(): void {
		$service = new EventRsvpService($this->createMock(ContainerInterface::class), $this->feedReader(null), $this->createMock(LoggerInterface::class));
		$this->assertFalse($service->rsvp('g1', 'e1', 'child-1', 'yes'));

		$disabled = $this->feedReader(['id' => 'e1', 'rsvpEnabled' => false]);
		$service2 = new EventRsvpService($this->createMock(ContainerInterface::class), $disabled, $this->createMock(LoggerInterface::class));
		$this->assertFalse($service2->rsvp('g1', 'e1', 'child-1', 'yes'));
	}//end testReturnsFalseWhenTheEventIsNotInAudienceOrRsvpDisabled()

	public function testReturnsFalseForAChildThatIsNotTheGuardiansOwn(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new EventRsvpService($container, $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true], false), $this->createMock(LoggerInterface::class));
		$this->assertFalse($service->rsvp('g1', 'e1', 'someone-elses-child', 'yes'));
	}//end testReturnsFalseForAChildThatIsNotTheGuardiansOwn()

	public function testRejectsAnInvalidResponseValue(): void {
		$service = new EventRsvpService($this->createMock(ContainerInterface::class), $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true]), $this->createMock(LoggerInterface::class));
		$this->assertFalse($service->rsvp('g1', 'e1', 'child-1', 'HACKED'));
	}//end testRejectsAnInvalidResponseValue()

	public function testFirstRsvpCreatesANewRecord(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public ?string $uuid = 'unset';

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				$this->uuid = $uuid;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventRsvpService($container, $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true]), $this->createMock(LoggerInterface::class));
		$this->assertTrue($service->rsvp('g1', 'e1', 'child-1', 'maybe'));

		$this->assertSame('maybe', $objectService->saved['response']);
		$this->assertNull($objectService->uuid, 'a first RSVP has no existing id to update');
	}//end testFirstRsvpCreatesANewRecord()

	public function testASecondRsvpUpdatesRatherThanDuplicates(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public ?string $uuid = 'unset';

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$rows = [
					['id' => 'rsvp-1', 'eventRef' => 'e1', 'guardianRef' => 'g1', 'childRef' => 'child-1', 'response' => 'maybe'],
					['id' => 'rsvp-other', 'eventRef' => 'e1', 'guardianRef' => 'g1', 'childRef' => 'child-2', 'response' => 'no'],
				];

				// The store answers the filters as OpenRegister does: a row
				// matches only when every filtered property equals the value.
				$rows = array_values(array_filter($rows, static function (array $row) use ($config): bool {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}));

				return $rows;
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				$this->uuid = $uuid;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventRsvpService($container, $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true]), $this->createMock(LoggerInterface::class));
		$this->assertTrue($service->rsvp('g1', 'e1', 'child-1', 'yes'));

		$this->assertSame('yes', $objectService->saved['response']);
		$this->assertSame('rsvp-1', $objectService->uuid, 'the existing id must be reused so OR updates rather than creates');
	}//end testASecondRsvpUpdatesRatherThanDuplicates()

	/**
	 * A service over a store that keeps what it saves and answers filters the way OpenRegister does.
	 *
	 * @param array<string,mixed>            $event The event the reader returns.
	 * @param array<int,array<string,mixed>> $rows  The answers already in the store.
	 * @param bool                           $ownChild Whether the guardian's child check passes.
	 *
	 * @return array{0: EventRsvpService, 1: object}
	 */
	private function serviceOver(array $event, array $rows, bool $ownChild = true): array {
		$store = new class ($rows) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			public array $rows;

			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return array_values(array_filter($this->rows, static function (array $row) use ($config): bool {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}));
			}//end findAll()

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($uuid !== null) {
					foreach ($this->rows as $i => $row) {
						if ($row['id'] === $uuid) {
							$this->rows[$i] = ['id' => $uuid] + $object;
							return $this->rows[$i];
						}
					}
				}

				$this->rows[] = ['id' => 'new-' . count($this->rows)] + $object;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $store : throw new RuntimeException('no service'));

		return [new EventRsvpService($container, $this->feedReader($event, $ownChild), $this->createMock(LoggerInterface::class)), $store];
	}//end serviceOver()

	/**
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-2
	 */
	public function testALearnerAnswersForHerselfOnlyWhenTheEventAllowsIt(): void {
		$event = ['id' => 'e1', 'rsvpEnabled' => true, 'askSeats' => true, 'capacity' => 300];

		[$service, $store] = $this->serviceOver($event + ['rsvpBy' => ['guardian', 'learner']], [], false);
		$this->assertNull($service->attempt('noor', 'e1', 'noor', 'yes', 2));
		$this->assertSame(1, count($store->rows));
		$this->assertSame(2, $store->rows[0]['seats']);
		$this->assertSame('noor', $store->rows[0]['guardianRef']);

		[$guardianOnly, $untouched] = $this->serviceOver($event, [], false);
		$this->assertSame(EventRsvpService::REASON_NOT_FOUND, $guardianOnly->attempt('noor', 'e1', 'noor', 'yes', 2), 'rsvpBy defaults to the guardian');
		$this->assertSame([], $untouched->rows);

		[$other, $none] = $this->serviceOver($event + ['rsvpBy' => ['learner']], [], false);
		$this->assertSame(EventRsvpService::REASON_NOT_FOUND, $other->attempt('noor', 'e1', 'sami', 'yes', 1), 'a learner answers for herself, not another child');
		$this->assertSame([], $none->rows);
	}//end testALearnerAnswersForHerselfOnlyWhenTheEventAllowsIt()

	/**
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-2
	 */
	public function testOneAnswerPerChildWhoeverGaveIt(): void {
		$event = ['id' => 'e1', 'rsvpEnabled' => true, 'askSeats' => true, 'capacity' => 300, 'rsvpBy' => ['guardian', 'learner']];
		$rows  = [['id' => 'r1', 'eventRef' => 'e1', 'guardianRef' => 'noor', 'childRef' => 'noor', 'response' => 'yes', 'seats' => 2]];

		[$service, $store] = $this->serviceOver($event, $rows);
		$this->assertNull($service->attempt('erik', 'e1', 'noor', 'yes', 3));

		$this->assertCount(1, $store->rows, 'still one answer for Noor');
		$this->assertSame(3, $store->rows[0]['seats']);
		$this->assertSame('erik', $store->rows[0]['guardianRef']);
		$this->assertSame(3, EventRsvpService::seatsTaken($store->rows));
	}//end testOneAnswerPerChildWhoeverGaveIt()

	/**
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-2
	 */
	public function testSeatsAreHeldToTheMaximumAndTheCapacity(): void {
		$event = ['id' => 'e1', 'rsvpEnabled' => true, 'askSeats' => true, 'capacity' => 5, 'maxSeatsPerAnswer' => 3];
		$rows  = [
			['id' => 'r1', 'eventRef' => 'e1', 'guardianRef' => 'g1', 'childRef' => 'c1', 'response' => 'yes', 'seats' => 3],
			['id' => 'r2', 'eventRef' => 'e1', 'guardianRef' => 'g2', 'childRef' => 'c2', 'response' => 'no', 'seats' => 0],
		];

		[$service, $store] = $this->serviceOver($event, $rows);
		$this->assertSame(EventRsvpService::REASON_SEATS, $service->attempt('g3', 'e1', 'c3', 'yes', 4), 'above the maximum per answer');
		$this->assertSame(EventRsvpService::REASON_SEATS, $service->attempt('g3', 'e1', 'c3', 'yes', 0));
		$this->assertSame(EventRsvpService::REASON_SEATS, $service->attempt('g3', 'e1', 'c3', 'yes', null));
		$this->assertSame(EventRsvpService::REASON_FULL, $service->attempt('g3', 'e1', 'c3', 'yes', 3), '3 and 3 pass 5');
		$this->assertCount(2, $store->rows, 'a refused answer saves nothing');

		$this->assertNull($service->attempt('g3', 'e1', 'c3', 'yes', 2), '3 and 2 reach 5');
		$this->assertNull($service->attempt('g1', 'e1', 'c1', 'yes', 3), 'her own earlier answer does not count against her new one');
		$this->assertNull($service->attempt('g4', 'e1', 'c4', 'no'), 'no needs no seats and no room');
		$this->assertSame(0, $store->rows[3]['seats']);
	}//end testSeatsAreHeldToTheMaximumAndTheCapacity()

	/**
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-2
	 */
	public function testAnswersAreRefusedAfterTheDeadline(): void {
		$event = ['id' => 'e1', 'rsvpEnabled' => true, 'signupDeadline' => '2026-10-30'];

		[$service, $store] = $this->serviceOver($event, []);
		$this->assertNull($service->attempt('g1', 'e1', 'c1', 'yes', null, '2026-10-30T22:00:00+01:00'), 'the deadline day itself is open');
		$this->assertSame(EventRsvpService::REASON_CLOSED, $service->attempt('g1', 'e1', 'c1', 'no', null, '2026-10-31T00:00:01+01:00'));
		$this->assertSame('yes', $store->rows[0]['response'], 'a closed answer changes nothing');
		$this->assertArrayNotHasKey('seats', $store->rows[0], 'an event without seats records none');

		[$broken] = $this->serviceOver(['id' => 'e1', 'rsvpEnabled' => true, 'signupDeadline' => 'someday'], []);
		$this->assertSame(EventRsvpService::REASON_CLOSED, $broken->attempt('g1', 'e1', 'c1', 'yes'), 'a deadline that does not parse closes the sign-up');
	}//end testAnswersAreRefusedAfterTheDeadline()
}//end class
