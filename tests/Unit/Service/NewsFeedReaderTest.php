<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\NewsFeedReader;
use OCA\Portaliq\Service\NewsPhotoConsentGate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the guardian read path: only published, in-audience items/newsletters
 * are returned, a draft is byte-identical to absent, and an out-of-audience
 * or non-existent id both answer null (no existence oracle).
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 */
class NewsFeedReaderTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * @param array<int, array<string, mixed>> $newsItemRows
	 * @param array<int, array<string, mixed>> $newsletterRows
	 */
	private function container(array $newsItemRows, array $newsletterRows = []): ContainerInterface {
		$objectService = new class($newsItemRows, $newsletterRows) {
			/**
			 * @param array<int, array<string, mixed>> $newsItemRows
			 * @param array<int, array<string, mixed>> $newsletterRows
			 */
			public function __construct(
				private array $newsItemRows,
				private array $newsletterRows,
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
				return ($this->schema === 'newsletter') ? $this->newsletterRows : $this->newsItemRows;
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

	private function passThroughGate(): NewsPhotoConsentGate {
		$gate = $this->createMock(NewsPhotoConsentGate::class);
		$gate->method('apply')->willReturnArgument(0);
		return $gate;
	}//end passThroughGate()

	public function testFeedReturnsOnlyPublishedInAudienceItems(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => '', 'groupRefs' => ['groep-5a'], 'childRefs' => [], 'photoConsent' => []]);

		$rows = [
			['id' => 'n1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'In audience'],
			['id' => 'n2', 'status' => 'published', 'target' => ['groupRefs' => ['groep-9z']], 'title' => 'Out of audience'],
			['id' => 'n3', 'status' => 'draft', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'Draft, in audience'],
		];

		$reader = new NewsFeedReader($this->container($rows), $audienceReader, $this->passThroughGate(), $this->createMock(LoggerInterface::class));
		$feed = $reader->feedFor('guardian-anna-devries');

		$this->assertCount(1, $feed);
		$this->assertSame('In audience', $feed[0]['title']);
	}//end testFeedReturnsOnlyPublishedInAudienceItems()

	public function testReadOwnItemReturnsNullForOutOfAudienceAndForNonExistentIdenticaly(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => '', 'groupRefs' => ['groep-5a'], 'childRefs' => [], 'photoConsent' => []]);

		$rows = [
			['id' => 'n1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-9z']], 'title' => 'Foreign'],
		];

		$reader = new NewsFeedReader($this->container($rows), $audienceReader, $this->passThroughGate(), $this->createMock(LoggerInterface::class));

		$this->assertNull($reader->readOwnItem('guardian-anna-devries', 'n1'));
		$this->assertNull($reader->readOwnItem('guardian-anna-devries', 'does-not-exist'));
	}//end testReadOwnItemReturnsNullForOutOfAudienceAndForNonExistentIdenticaly()

	public function testReadOwnItemReturnsTheItemWhenInAudienceAndPublished(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => '', 'groupRefs' => ['groep-5a'], 'childRefs' => [], 'photoConsent' => []]);

		$rows = [['id' => 'n1', 'status' => 'published', 'target' => ['groupRefs' => ['groep-5a']], 'title' => 'Mine']];

		$reader = new NewsFeedReader($this->container($rows), $audienceReader, $this->passThroughGate(), $this->createMock(LoggerInterface::class));

		$item = $reader->readOwnItem('guardian-anna-devries', 'n1');
		$this->assertNotNull($item);
		$this->assertSame('Mine', $item['title']);
	}//end testReadOwnItemReturnsTheItemWhenInAudienceAndPublished()

	public function testArchiveReturnsOnlySentInAudienceNewslettersMostRecentFirst(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => 'school-a', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []]);

		$newsletters = [
			['id' => 'nl1', 'sentAt' => '2026-09-01T00:00:00+00:00', 'target' => ['schoolRef' => 'school-a'], 'title' => 'Older'],
			['id' => 'nl2', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-a'], 'title' => 'Newer'],
			['id' => 'nl3', 'sentAt' => null, 'target' => ['schoolRef' => 'school-a'], 'title' => 'Draft'],
			['id' => 'nl4', 'sentAt' => '2026-09-10T00:00:00+00:00', 'target' => ['schoolRef' => 'other-school'], 'title' => 'Out of audience'],
		];

		$reader = new NewsFeedReader($this->container([], $newsletters), $audienceReader, $this->passThroughGate(), $this->createMock(LoggerInterface::class));
		$archive = $reader->archiveFor('guardian-anna-devries');

		$this->assertCount(2, $archive);
		$this->assertSame('Newer', $archive[0]['title']);
		$this->assertSame('Older', $archive[1]['title']);
	}//end testArchiveReturnsOnlySentInAudienceNewslettersMostRecentFirst()
}//end class
