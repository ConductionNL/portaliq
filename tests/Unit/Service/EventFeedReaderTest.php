<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Mirrors NewsFeedReaderTest from the sibling change: only published,
 * in-audience events return, each annotated with the CALLING guardian's own
 * RSVP and never another guardian's.
 *
 * @spec openspec/changes/events-and-signups/design.md#architecture-overview
 */
class EventFeedReaderTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * @param array<int, array<string, mixed>> $eventRows
	 * @param array<int, array<string, mixed>> $rsvpRows
	 */
	private function container(array $eventRows, array $rsvpRows = []): ContainerInterface {
		$objectService = new class ($eventRows, $rsvpRows) {
			/**
			 * @param array<int, array<string, mixed>> $eventRows
			 * @param array<int, array<string, mixed>> $rsvpRows
			 */
			public function __construct(
				private array $eventRows,
				private array $rsvpRows,
			) {
			}//end __construct()

			private string $schema = '';

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$rows = ($this->schema === 'eventRsvp') ? $this->rsvpRows : $this->eventRows;

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
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return $container;
	}//end container()

	private function audienceReader(array $groupRefs, array $childRefs = []): GuardianAudienceFixtureReader {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->method('resolveAudience')->willReturn(['schoolRef' => '', 'groupRefs' => $groupRefs, 'childRefs' => $childRefs, 'photoConsent' => []]);
		return $reader;
	}//end audienceReader()

	public function testFeedReturnsOnlyPublishedInAudienceEventsWithOwnRsvp(): void {
		$events = [
			['id' => 'e1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'In audience'],
			['id' => 'e2', 'status' => 'published', 'target' => ['groupRefs' => ['groep-9z']], 'title' => 'Out of audience'],
		];
		$rsvps = [
			['eventRef' => 'e1', 'guardianRef' => 'guardian-anna-devries', 'response' => 'yes'],
			['eventRef' => 'e1', 'guardianRef' => 'guardian-other', 'response' => 'no'],
		];

		$reader = new EventFeedReader($this->container($events, $rsvps), $this->audienceReader(['groep-5a']), $this->createMock(LoggerInterface::class));
		$feed = $reader->feedFor('guardian-anna-devries');

		$this->assertCount(1, $feed);
		$this->assertSame('In audience', $feed[0]['title']);
		$this->assertSame('yes', $feed[0]['myRsvp']);
	}//end testFeedReturnsOnlyPublishedInAudienceEventsWithOwnRsvp()

	public function testReadOwnEventReturnsNullForOutOfAudienceOrNonExistent(): void {
		$events = [['id' => 'e1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-9z']], 'title' => 'Foreign']];

		$reader = new EventFeedReader($this->container($events), $this->audienceReader(['groep-5a']), $this->createMock(LoggerInterface::class));

		$this->assertNull($reader->readOwnEvent('guardian-anna-devries', 'e1'));
		$this->assertNull($reader->readOwnEvent('guardian-anna-devries', 'does-not-exist'));
	}//end testReadOwnEventReturnsNullForOutOfAudienceOrNonExistent()

	public function testIsOwnChildOnlyForAChildInTheGuardiansAudience(): void {
		$reader = new EventFeedReader($this->container([]), $this->audienceReader(['groep-5a'], ['child-1']), $this->createMock(LoggerInterface::class));

		$this->assertTrue($reader->isOwnChild('guardian-anna-devries', 'child-1'));
		$this->assertFalse($reader->isOwnChild('guardian-anna-devries', 'someone-elses-child'));
		$this->assertFalse($reader->isOwnChild('guardian-anna-devries', ''));
	}//end testIsOwnChildOnlyForAChildInTheGuardiansAudience()

	public function testAnEventAskingSeatsCountsEveryYesAnswersSeats(): void {
		$events = [
			['id' => 'e1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'Musical', 'askSeats' => true],
			['id' => 'e2', 'status' => 'published', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'Sports day'],
		];
		$rsvps = [
			['eventRef' => 'e1', 'guardianRef' => 'guardian-anna-devries', 'response' => 'yes', 'seats' => 3],
			['eventRef' => 'e1', 'guardianRef' => 'guardian-other', 'response' => 'yes'],
			['eventRef' => 'e1', 'guardianRef' => 'guardian-third', 'response' => 'no', 'seats' => 4],
			['eventRef' => 'e2', 'guardianRef' => 'guardian-other', 'response' => 'yes', 'seats' => 9],
		];

		$reader = new EventFeedReader($this->container($events, $rsvps), $this->audienceReader(['groep-5a']), $this->createMock(LoggerInterface::class));
		$feed = $reader->feedFor('guardian-anna-devries');

		$this->assertCount(2, $feed);
		$this->assertSame(4, $feed[0]['seatsTaken'], 'three seats for anna and one for a yes without seats; the no and the other event do not count');
		$this->assertSame('yes', $feed[0]['myRsvp']);
		$this->assertArrayNotHasKey('seatsTaken', $feed[1]);
		$this->assertNull($feed[1]['myRsvp']);
	}//end testAnEventAskingSeatsCountsEveryYesAnswersSeats()

	public function testReadOwnEventReturnsAnEventInTheAudienceAndNullForAnEmptyId(): void {
		$events = [
			['id' => 'e0', 'status' => 'published', 'target' => ['groupRefs' => ['groep-9z']], 'title' => 'Foreign'],
			['id' => 'e1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'Ours'],
		];

		$reader = new EventFeedReader($this->container($events), $this->audienceReader(['groep-5a']), $this->createMock(LoggerInterface::class));

		$this->assertSame('Ours', $reader->readOwnEvent('guardian-anna-devries', 'e1')['title'] ?? null);
		$this->assertNull($reader->readOwnEvent('guardian-anna-devries', ''));
		$this->assertFalse($reader->isOwnChild('', 'child-1'));
	}//end testReadOwnEventReturnsAnEventInTheAudienceAndNullForAnEmptyId()
	public function testAPupilLearnsSheMayAnswerAndSeesTheAnswerGivenForHer(): void {
		$events = [
			['id' => 'e1', 'status' => 'published', 'target' => ['groupRefs' => ['3havo']], 'rsvpEnabled' => true, 'rsvpBy' => ['guardian', 'learner'], 'askSeats' => true],
			['id' => 'e2', 'status' => 'published', 'target' => ['groupRefs' => ['3havo']], 'rsvpEnabled' => true],
		];
		$rsvps = [
			['eventRef' => 'e1', 'guardianRef' => 'guardian-erik', 'childRef' => 'pupil-noor', 'response' => 'yes', 'seats' => 3],
		];

		$reader = new EventFeedReader($this->container($events, $rsvps), $this->audienceReader(['3havo']), $this->createMock(LoggerInterface::class));
		$feed = $reader->feedFor('pupil-noor');

		$this->assertCount(2, $feed);
		$this->assertTrue($feed[0]['mayAnswerSelf']);
		$this->assertSame('pupil-noor', $feed[0]['selfRef']);
		$this->assertSame(['response' => 'yes', 'seats' => 3], $feed[0]['ownAnswer'], 'her father\'s answer is the one answer for her');
		$this->assertSame(3, $feed[0]['seatsTaken']);
		$this->assertNull($feed[0]['myRsvp'], 'she did not give it herself');
		$this->assertFalse($feed[1]['mayAnswerSelf'], 'an event without rsvpBy is answered by guardians alone');
		$this->assertArrayNotHasKey('ownAnswer', $feed[1]);
		$this->assertArrayNotHasKey('selfRef', $feed[1]);
	}//end testAPupilLearnsSheMayAnswerAndSeesTheAnswerGivenForHer()

	public function testNoAnswerForHerYetIsNull(): void {
		$events = [['id' => 'e1', 'status' => 'published', 'target' => ['groupRefs' => ['3havo']], 'rsvpEnabled' => true, 'rsvpBy' => ['learner']]];
		$rsvps = [['eventRef' => 'e1', 'guardianRef' => 'guardian-erik', 'childRef' => 'pupil-other', 'response' => 'yes']];

		$reader = new EventFeedReader($this->container($events, $rsvps), $this->audienceReader(['3havo']), $this->createMock(LoggerInterface::class));
		$feed = $reader->feedFor('pupil-noor');

		$this->assertTrue($feed[0]['mayAnswerSelf']);
		$this->assertNull($feed[0]['ownAnswer']);
	}//end testNoAnswerForHerYetIsNull()
}//end class
