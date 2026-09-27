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
				return ($this->schema === 'eventRsvp') ? $this->rsvpRows : $this->eventRows;
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

	private function audienceReader(array $groupRefs): GuardianAudienceFixtureReader {
		$reader = $this->createMock(GuardianAudienceFixtureReader::class);
		$reader->method('resolveAudience')->willReturn(['schoolRef' => '', 'groupRefs' => $groupRefs, 'childRefs' => [], 'photoConsent' => []]);
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
}//end class
