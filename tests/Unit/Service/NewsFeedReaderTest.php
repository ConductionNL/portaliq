<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\Messaging\GuardianMessageTranslator;
use OCA\Portaliq\Service\Messaging\MessageStore;
use OCA\Portaliq\Service\Messaging\MessageTranslationClient;
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

	/**
	 * A translator whose client answers labelled Arabic translations of Dutch
	 * text, over a store that records every save.
	 *
	 * @param array<int, array<string, mixed>> $saved Receives each save.
	 *
	 * @return GuardianMessageTranslator
	 */
	private function translator(array &$saved): GuardianMessageTranslator {
		$client = $this->getMockBuilder(MessageTranslationClient::class)
			->disableOriginalConstructor()
			->onlyMethods(['translate'])
			->getMock();
		$client->method('translate')->willReturnCallback(
			static fn (string $text, string $targetLanguage, string $originalRef): array => [
				'targetLanguage' => $targetLanguage,
				'text' => '[' . $targetLanguage . '] ' . $text,
				'translatedByAi' => true,
				'sourceLanguage' => 'nl',
				'originalRef' => $originalRef,
			]
		);

		$store = $this->getMockBuilder(MessageStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save'])
			->getMock();
		$store->method('save')->willReturnCallback(
			static function (string $schema, array $object, ?string $uuid = null) use (&$saved): string {
				$saved[] = ['schema' => $schema, 'object' => $object, 'uuid' => $uuid];
				return (string)$uuid;
			}
		);

		return new GuardianMessageTranslator(client: $client, store: $store);
	}//end translator()

	/**
	 * The feed translates a news body into the reader's language, keeps the
	 * original, and stores the STORED row: the photo gate's redaction of the
	 * reader's copy never reaches storage.
	 *
	 * @spec openspec/changes/news-item-translation/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 */
	public function testFeedTranslatesTheBodyAndStoresTheStoredRow(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => 'school-1', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []]);
		$gate = $this->createMock(NewsPhotoConsentGate::class);
		$gate->method('apply')->willReturnCallback(static fn (array $item): array => array_merge($item, ['photoRefs' => []]));

		$rows = [['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Studiedag', 'body' => 'De school is morgen dicht.', 'photoRefs' => ['foto-1']]];
		$saved = [];
		$reader = new NewsFeedReader($this->container($rows), $audienceReader, $gate, $this->createMock(LoggerInterface::class), $this->translator($saved));

		$feed = $reader->feedFor('guardian-anna-devries', 'ar');

		$this->assertSame('[ar] De school is morgen dicht.', $feed[0]['translation']['text']);
		$this->assertSame('De school is morgen dicht.', $feed[0]['body']);
		$this->assertCount(1, $saved);
		$this->assertSame('newsItem', $saved[0]['schema']);
		$this->assertSame('n1', $saved[0]['uuid']);
		$this->assertSame(['foto-1'], $saved[0]['object']['photoRefs']);
		$this->assertSame('portaliq:newsItem:n1', $saved[0]['object']['translations'][0]['originalRef']);
		$this->assertSame('De school is morgen dicht.', $saved[0]['object']['body']);
	}//end testFeedTranslatesTheBodyAndStoresTheStoredRow()

	/**
	 * Without a language nothing is translated and nothing is stored.
	 *
	 * @spec openspec/changes/news-item-translation/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 */
	public function testFeedWithoutALanguageTranslatesNothing(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => 'school-1', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []]);
		$rows = [['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'body' => 'Tekst']];
		$saved = [];
		$reader = new NewsFeedReader($this->container($rows), $audienceReader, $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$feed = $reader->feedFor('guardian-anna-devries');

		$this->assertArrayNotHasKey('translation', $feed[0]);
		$this->assertSame([], $saved);
	}//end testFeedWithoutALanguageTranslatesNothing()

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

	/**
	 * Storage order is oldest first, so a consumer that takes the first few
	 * (the record page's news block) showed the oldest. The feed answers
	 * newest first by publication moment, created when it was never
	 * published through OpenRegister; an undated item sorts last.
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
	 */
	public function testFeedIsNewestFirstWhateverTheStorageOrder(): void {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => '', 'groupRefs' => ['groep-7'], 'childRefs' => [], 'photoConsent' => []]);

		$target = ['groupRefs' => ['groep-7']];
		$rows = [
			['id' => 'undated', 'status' => 'published', 'target' => $target, 'title' => 'Undated'],
			['id' => 'oldest', 'status' => 'published', 'target' => $target, 'title' => 'Oldest', '@self' => ['created' => '2026-09-01T08:00:00+00:00']],
			['id' => 'middle', 'status' => 'published', 'target' => $target, 'title' => 'Middle', '@self' => ['created' => '2026-09-15T10:00:00+02:00']],
			['id' => 'published', 'status' => 'published', 'target' => $target, 'title' => 'Published late', '@self' => ['created' => '2026-08-01T08:00:00+00:00', 'published' => '2026-09-20T08:00:00+00:00']],
			['id' => 'newest', 'status' => 'published', 'target' => $target, 'title' => 'Newest', '@self' => ['created' => '2026-10-02T09:00:00+00:00']],
		];

		$reader = new NewsFeedReader($this->container($rows), $audienceReader, $this->passThroughGate(), $this->createMock(LoggerInterface::class));
		$feed = $reader->feedFor('guardian-fatima');

		$this->assertSame(['newest', 'published', 'middle', 'oldest', 'undated'], array_column($feed, 'id'));
	}//end testFeedIsNewestFirstWhateverTheStorageOrder()

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

	/**
	 * The audience of school 1 for the translation tests.
	 *
	 * @return GuardianAudienceFixtureReader
	 */
	private function schoolOneAudience(): GuardianAudienceFixtureReader {
		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('resolveAudience')->willReturn(['schoolRef' => 'school-1', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []]);
		return $audienceReader;
	}//end schoolOneAudience()

	/**
	 * The title is translated with the body into the same entry: one save, one
	 * provenance, one notice.
	 *
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	public function testFeedTranslatesTheTitleWithTheBody(): void {
		$rows = [['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Studiedag', 'body' => 'De school is morgen dicht.']];
		$saved = [];
		$reader = new NewsFeedReader($this->container($rows), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$feed = $reader->feedFor('guardian-anna-devries', 'ar');

		$this->assertSame('[ar] Studiedag', $feed[0]['translation']['title']);
		$this->assertSame('[ar] De school is morgen dicht.', $feed[0]['translation']['text']);
		$this->assertSame('Studiedag', $feed[0]['title']);
		$this->assertCount(1, $saved);
		$this->assertCount(1, $saved[0]['object']['translations']);
		$this->assertSame('[ar] Studiedag', $saved[0]['object']['translations'][0]['title']);
		$this->assertSame('portaliq:newsItem:n1', $saved[0]['object']['translations'][0]['originalRef']);
	}//end testFeedTranslatesTheTitleWithTheBody()

	/**
	 * A news item translated before titles were gets its title added to the
	 * stored entry, not a second entry.
	 *
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	public function testAStoredTranslationWithoutATitleGetsItsTitle(): void {
		$entry = ['targetLanguage' => 'ar', 'text' => 'نص', 'translatedByAi' => true, 'sourceLanguage' => 'nl', 'originalRef' => 'portaliq:newsItem:n1'];
		$rows = [['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Studiedag', 'body' => 'Tekst', 'translations' => [$entry]]];
		$saved = [];
		$reader = new NewsFeedReader($this->container($rows), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$feed = $reader->feedFor('guardian-anna-devries', 'ar');

		$this->assertSame('نص', $feed[0]['translation']['text']);
		$this->assertSame('[ar] Studiedag', $feed[0]['translation']['title']);
		$this->assertCount(1, $saved);
		$this->assertCount(1, $saved[0]['object']['translations']);
		$this->assertSame('[ar] Studiedag', $saved[0]['object']['translations'][0]['title']);

		// Read again with the stored title: nothing more to save.
		$saved2 = [];
		$again = new NewsFeedReader($this->container([$saved[0]['object'] + ['id' => 'n1']]), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved2));
		$this->assertSame('[ar] Studiedag', $again->feedFor('guardian-anna-devries', 'ar')[0]['translation']['title']);
		$this->assertSame([], $saved2);
	}//end testAStoredTranslationWithoutATitleGetsItsTitle()

	/**
	 * The archive carries each newsletter's items, only published and in the
	 * reader's audience, translated and photo-gated exactly like the feed.
	 *
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-the-newsletter-archive-shows-its-items-as-the-news-page-does
	 */
	public function testArchiveCarriesItsItemsTranslatedLikeTheFeed(): void {
		$gate = $this->createMock(NewsPhotoConsentGate::class);
		$gate->method('apply')->willReturnCallback(static fn (array $item): array => array_merge($item, ['photoRefs' => []]));
		$items = [
			['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Studiedag', 'body' => 'Dicht.', 'photoRefs' => ['foto-1']],
			['id' => 'n2', 'status' => 'draft', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Concept', 'body' => 'Nog niet.'],
			['id' => 'n3', 'status' => 'published', 'target' => ['schoolRef' => 'school-9'], 'title' => 'Andere school', 'body' => 'Niet voor jou.'],
		];
		$newsletters = [['id' => 'nl1', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'September', 'itemRefs' => ['n1', 'n2', 'n3', 'missing']]];
		$saved = [];
		$reader = new NewsFeedReader($this->container($items, $newsletters), $this->schoolOneAudience(), $gate, $this->createMock(LoggerInterface::class), $this->translator($saved));

		$archive = $reader->archiveFor('guardian-anna-devries', 'ar');

		$this->assertCount(1, $archive[0]['items']);
		$this->assertSame('n1', $archive[0]['items'][0]['id']);
		$this->assertSame('[ar] Dicht.', $archive[0]['items'][0]['translation']['text']);
		$this->assertSame('[ar] Studiedag', $archive[0]['items'][0]['translation']['title']);
		$this->assertSame([], $archive[0]['items'][0]['photoRefs']);
		$itemSaves = array_values(array_filter($saved, static fn (array $save): bool => $save['schema'] === 'newsItem'));
		$this->assertSame(['foto-1'], $itemSaves[0]['object']['photoRefs']);
		$this->assertSame('September', $archive[0]['title']);
	}//end testArchiveCarriesItsItemsTranslatedLikeTheFeed()

	/**
	 * Without a language the archive still carries its items, as written.
	 *
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-the-newsletter-archive-shows-its-items-as-the-news-page-does
	 */
	public function testArchiveWithoutALanguageCarriesItsItemsAsWritten(): void {
		$items = [['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Studiedag', 'body' => 'Dicht.']];
		$newsletters = [['id' => 'nl1', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'September', 'itemRefs' => ['n1']]];
		$saved = [];
		$reader = new NewsFeedReader($this->container($items, $newsletters), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$archive = $reader->archiveFor('guardian-anna-devries');

		$this->assertSame('Studiedag', $archive[0]['items'][0]['title']);
		$this->assertArrayNotHasKey('translation', $archive[0]['items'][0]);
		$this->assertSame([], $saved);
	}//end testArchiveWithoutALanguageCarriesItsItemsAsWritten()

	/**
	 * A sent newsletter's own title is translated into its own `translations`
	 * entry, in the shape a news item keeps, with one hermiq call and one save
	 * on the newsletter row.
	 *
	 * @spec openspec/changes/newsletter-title-translation/specs/guardian-message-translation/spec.md#requirement-a-newsletter-keeps-its-title-translations-next-to-the-original
	 */
	public function testArchiveTranslatesTheNewslettersOwnTitle(): void {
		$newsletters = [['id' => 'nl1', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Nieuwsbrief september', 'itemRefs' => []]];
		$saved = [];
		$reader = new NewsFeedReader($this->container([], $newsletters), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$archive = $reader->archiveFor('guardian-anna-devries', 'ar');

		$this->assertSame('Nieuwsbrief september', $archive[0]['title']);
		$this->assertSame('[ar] Nieuwsbrief september', $archive[0]['translation']['title']);
		$this->assertSame('[ar] Nieuwsbrief september', $archive[0]['translation']['text']);
		$this->assertTrue($archive[0]['translation']['translatedByAi']);
		$this->assertCount(1, $saved);
		$this->assertSame('newsletter', $saved[0]['schema']);
		$this->assertSame('nl1', $saved[0]['uuid']);
		$this->assertSame('Nieuwsbrief september', $saved[0]['object']['title']);
		$this->assertArrayNotHasKey('items', $saved[0]['object']);
		$this->assertArrayNotHasKey('translation', $saved[0]['object']);
		$this->assertCount(1, $saved[0]['object']['translations']);
		$this->assertSame('[ar] Nieuwsbrief september', $saved[0]['object']['translations'][0]['title']);
		$this->assertSame('portaliq:newsletter:nl1', $saved[0]['object']['translations'][0]['originalRef']);
	}//end testArchiveTranslatesTheNewslettersOwnTitle()

	/**
	 * A stored newsletter title translation is reused: nothing is saved again.
	 *
	 * @spec openspec/changes/newsletter-title-translation/specs/guardian-message-translation/spec.md#requirement-a-newsletter-keeps-its-title-translations-next-to-the-original
	 */
	public function testArchiveReusesAStoredNewsletterTitleTranslation(): void {
		$entry = ['targetLanguage' => 'ar', 'text' => 'نشرة سبتمبر', 'title' => 'نشرة سبتمبر', 'translatedByAi' => true, 'sourceLanguage' => 'nl', 'originalRef' => 'portaliq:newsletter:nl1'];
		$newsletters = [['id' => 'nl1', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Nieuwsbrief september', 'itemRefs' => [], 'translations' => [$entry]]];
		$saved = [];
		$reader = new NewsFeedReader($this->container([], $newsletters), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$archive = $reader->archiveFor('guardian-anna-devries', 'ar');

		$this->assertSame('نشرة سبتمبر', $archive[0]['translation']['title']);
		$this->assertSame([], $saved);
	}//end testArchiveReusesAStoredNewsletterTitleTranslation()

	/**
	 * Newsletter titles and archive items share the one per-request bound of
	 * new translations.
	 *
	 * @spec openspec/changes/newsletter-title-translation/specs/guardian-message-translation/spec.md#requirement-a-newsletter-keeps-its-title-translations-next-to-the-original
	 */
	public function testNewsletterTitlesAndItemsShareThePerRequestBound(): void {
		$items = [
			['id' => 'n1', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Een', 'body' => 'Eerste.'],
			['id' => 'n2', 'status' => 'published', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Twee', 'body' => 'Tweede.'],
		];
		$newsletters = [
			['id' => 'nl1', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'September', 'itemRefs' => ['n1']],
			['id' => 'nl2', 'sentAt' => '2026-06-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'Juni', 'itemRefs' => ['n2']],
		];
		$saved = [];
		$reader = new NewsFeedReader($this->container($items, $newsletters), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$reader->archiveFor('guardian-anna-devries', 'ar');

		$this->assertCount(GuardianMessageTranslator::NEW_PER_REQUEST, $saved);
		$this->assertSame(['newsletter', 'newsletter', 'newsItem'], array_column($saved, 'schema'));
	}//end testNewsletterTitlesAndItemsShareThePerRequestBound()

	/**
	 * Without a language the newsletter title stays as written, with no notice.
	 *
	 * @spec openspec/changes/newsletter-title-translation/specs/guardian-message-translation/spec.md#requirement-a-newsletter-keeps-its-title-translations-next-to-the-original
	 */
	public function testArchiveWithoutALanguageLeavesTheNewsletterTitleAsWritten(): void {
		$newsletters = [['id' => 'nl1', 'sentAt' => '2026-09-15T00:00:00+00:00', 'target' => ['schoolRef' => 'school-1'], 'title' => 'September', 'itemRefs' => []]];
		$saved = [];
		$reader = new NewsFeedReader($this->container([], $newsletters), $this->schoolOneAudience(), $this->passThroughGate(), $this->createMock(LoggerInterface::class), $this->translator($saved));

		$archive = $reader->archiveFor('guardian-anna-devries');

		$this->assertArrayNotHasKey('translation', $archive[0]);
		$this->assertSame([], $saved);
	}//end testArchiveWithoutALanguageLeavesTheNewsletterTitleAsWritten()
}//end class
