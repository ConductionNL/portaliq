<?php

/**
 * Unit tests for TrafficIngestService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTime;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\GeoResolverInterface;
use OCA\Portaliq\Service\Traffic\ReferrerClassifier;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCA\Portaliq\Service\Traffic\UserAgentClassifier;
use OCA\Portaliq\Service\Traffic\VisitorHasher;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCA\Portaliq\Service\TrafficEventValidator;
use OCA\Portaliq\Service\TrafficIngestService;
use OCA\Portaliq\Tests\Unit\Service\Traffic\FakeAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

// The test tree is not autoloaded; the double is a plain include.
require_once __DIR__ . '/Traffic/FakeAppConfig.php';

/**
 * What reaches storage, and what never does.
 *
 * The one property every test here circles is the negative one: the
 * address and the user agent go in and do not come out. A positive control
 * (a real page view is stored with its derived families) is asserted
 * alongside, because a service that stored nothing would pass the negative
 * half perfectly.
 */
class TrafficIngestServiceTest extends TestCase {

	/**
	 * The records the fake store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The shared config store, so counters can be read back.
	 *
	 * @var FakeAppConfig
	 */
	private FakeAppConfig $config;

	/**
	 * A user agent every browser test sends.
	 */
	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = [];
		$this->config = new FakeAppConfig();
	}//end setUp()


	/**
	 * The service over a capturing store and a fixed clock.
	 *
	 * @param array<int, array<string, mixed>> $portals The published portals.
	 * @param string|null                      $region  What the geo resolver answers.
	 *
	 * @return TrafficIngestService The service.
	 */
	private function service(array $portals, ?string $region = null): TrafficIngestService {
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('allPublishedPortals')->willReturn($portals);

		$store = $this->createMock(TrafficEventStore::class);
		$store->method('append')->willReturnCallback(
			function (array $records): int {
				$this->stored = array_merge($this->stored, $records);
				return count($records);
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1788000000);
		$clock->method('getDateTime')->willReturn(new DateTime('@1788000000'));

		$geo = $this->createMock(GeoResolverInterface::class);
		$geo->method('resolve')->willReturn($region);

		$appConfig = $this->config->mock($this);

		return new TrafficIngestService(
			$resolver,
			new TrafficConfigResolver(),
			new TrafficEventValidator(),
			$store,
			new TrafficMetrics($appConfig),
			new VisitorHasher($appConfig, $clock),
			new UserAgentClassifier(),
			new ReferrerClassifier(),
			$geo,
			$clock
		);
	}//end service()


	/**
	 * A measuring portal.
	 *
	 * @param array<string, mixed> $traffic The traffic block.
	 *
	 * @return array<string, mixed> The portal record.
	 */
	private function portal(array $traffic = ['enabled' => true]): array {
		return ['slug' => 'open-tilburg', 'status' => 'published', 'traffic' => $traffic];
	}//end portal()


	/**
	 * The context a browser request produces.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function browser(): array {
		return ['ip' => '203.0.113.9', 'userAgent' => self::CHROME, 'acceptLanguage' => 'nl-NL,nl;q=0.9,en;q=0.8', 'consent' => true];
	}//end browser()


	/**
	 * A page view is stored with the derived families, and NEITHER the
	 * address NOR the agent appears in any stored field.
	 *
	 * @return void
	 */
	public function testAPageViewIsStoredWithoutTheAddressOrTheAgent(): void {
		$service = $this->service(portals: [$this->portal([
			'enabled' => true,
			'dimensions' => ['pageReferrer', 'pageTitle', 'deviceType', 'browser', 'os', 'language', 'referrerHost', 'channel'],
		])]);

		$result = $service->ingest(
			portalSlug: 'open-tilburg',
			events: [[
				'name' => 'page_view',
				'sequence' => 0,
				'pageLocation' => 'https://open-tilburg.nl/woo?q=achternaam',
				'pageReferrer' => 'https://www.google.nl/',
				'pageTitle' => 'Woo',
			]],
			context: $this->browser()
		);

		$this->assertSame(1, $result['accepted']);
		$this->assertCount(1, $this->stored);
		$record = $this->stored[0];

		// The positive half: it IS a record of the visit.
		$this->assertSame('open-tilburg', $record['portal']);
		$this->assertSame('/woo', $record['pagePath']);
		$this->assertSame('desktop', $record['deviceType']);
		$this->assertSame('Chrome', $record['browser']);
		$this->assertSame('Windows', $record['os']);
		$this->assertSame('nl', $record['language']);
		$this->assertSame('www.google.nl', $record['referrerHost']);
		$this->assertSame('organic search', $record['channel']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record['visitorHash']);
		$this->assertSame('2026-08-29T10:40:00Z', substr($record['receivedAt'], 0, 19) . 'Z');
		$this->assertSame('2026-11-27T10:40:00Z', $record['expires'], 'expires = received + 90 days');

		// The negative half, over EVERY field, including nested ones.
		$flat = json_encode($record);
		$this->assertStringNotContainsString('203.0.113.9', $flat);
		$this->assertStringNotContainsString('Mozilla', $flat);
		$this->assertStringNotContainsString('AppleWebKit', $flat);
		$this->assertArrayNotHasKey('userAgent', $record);
		$this->assertArrayNotHasKey('ip', $record);
	}//end testAPageViewIsStoredWithoutTheAddressOrTheAgent()


	/**
	 * A derived family the portal did not enable is not stored, even though
	 * it was derived.
	 *
	 * @return void
	 */
	public function testADerivedDimensionIsOnlyKeptWhenEnabled(): void {
		$service = $this->service(portals: [$this->portal(['enabled' => true, 'dimensions' => ['pageTitle']])], region: 'NL');

		$service->ingest(
			portalSlug: 'open-tilburg',
			events: [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/']],
			context: $this->browser()
		);

		foreach (['deviceType', 'browser', 'os', 'language', 'region', 'referrerHost', 'channel'] as $key) {
			$this->assertArrayNotHasKey($key, $this->stored[0], $key . ' was not enabled');
		}
	}//end testADerivedDimensionIsOnlyKeptWhenEnabled()


	/**
	 * Region follows the geo resolver AND the portal's granularity.
	 *
	 * @return void
	 */
	public function testRegionIsStoredOnlyWhenEnabledAndResolvable(): void {
		$on = $this->service(portals: [$this->portal(['enabled' => true, 'dimensions' => ['region']])], region: 'NL');
		$on->ingest(portalSlug: 'open-tilburg', events: [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/']], context: $this->browser());
		$this->assertSame('NL', $this->stored[0]['region']);

		$this->stored = [];
		$none = $this->service(
			portals: [$this->portal(['enabled' => true, 'dimensions' => ['region'], 'regionGranularity' => 'none'])],
			region: 'NL'
		);
		$none->ingest(portalSlug: 'open-tilburg', events: [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/']], context: $this->browser());
		$this->assertArrayNotHasKey('region', $this->stored[0], 'granularity none stores no location whatever the resolver knows');
	}//end testRegionIsStoredOnlyWhenEnabledAndResolvable()


	/**
	 * A crawler's batch is refused whole, counted, and stores nothing.
	 *
	 * @return void
	 */
	public function testACrawlerIsRefusedAndCounted(): void {
		$service = $this->service(portals: [$this->portal()]);

		$result = $service->ingest(
			portalSlug: 'open-tilburg',
			events: [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/']],
			context: ['ip' => '203.0.113.9', 'userAgent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']
		);

		$this->assertSame(['accepted' => 0, 'refused' => ['bot' => 1]], $result);
		$this->assertSame([], $this->stored);
		$this->assertSame(1, $this->config->values['portaliq/' . TrafficMetrics::REFUSED_PREFIX . 'bot']);
	}//end testACrawlerIsRefusedAndCounted()


	/**
	 * A roll-up portal (portal-traffic-reporting) has no visitors of its
	 * own: a batch aimed at it is refused whole and counted, and a log
	 * import's `allowOld` lets an old timestamp through where a live
	 * beacon's would be refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function testARollUpPortalRefusesEventsAndAnImportMayBeOld(): void {
		$service = $this->service(portals: [
			['slug' => 'rollup', 'status' => 'published', 'traffic' => ['enabled' => true, 'rollupOf' => ['open-tilburg']]],
			$this->portal(),
		]);
		$event = ['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/', 'timestamp' => '2026-07-01T10:00:00Z'];

		$refused = $service->ingest(portalSlug: 'rollup', events: [$event], context: $this->browser());
		$this->assertSame(['accepted' => 0, 'refused' => ['rollup-portal' => 1]], $refused);
		$this->assertSame(1, $this->config->values['portaliq/' . TrafficMetrics::REFUSED_PREFIX . 'rollup-portal']);

		$tooOld = $service->ingest(portalSlug: 'open-tilburg', events: [$event], context: $this->browser());
		$this->assertSame(['accepted' => 0, 'refused' => ['timestamp-out-of-range' => 1]], $tooOld);

		$imported = $service->ingest(portalSlug: 'open-tilburg', events: [$event], context: $this->browser() + ['allowOld' => true]);
		$this->assertSame(1, $imported['accepted']);
		$this->assertSame('2026-07-01T10:00:00.000Z', $this->stored[0]['occurredAt']);
	}//end testARollUpPortalRefusesEventsAndAnImportMayBeOld()


	/**
	 * A disabled portal refuses every event under its own reason, and an
	 * unknown slug refuses under another. Both are counted.
	 *
	 * @return void
	 */
	public function testDisabledAndUnknownPortalsRefuseWithDistinctReasons(): void {
		$service = $this->service(portals: [['slug' => 'open-venray', 'status' => 'published']]);
		$events = [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/']];

		$disabled = $service->ingest(portalSlug: 'open-venray', events: $events, context: $this->browser());
		$this->assertSame(['measurement-disabled' => 1], $disabled['refused']);

		$unknown = $service->ingest(portalSlug: 'nowhere', events: $events, context: $this->browser());
		$this->assertSame(['unknown-portal' => 1], $unknown['refused']);

		$this->assertSame([], $this->stored);
		$this->assertSame(1, $this->config->values['portaliq/' . TrafficMetrics::REFUSED_PREFIX . 'measurement-disabled']);
		$this->assertSame(1, $this->config->values['portaliq/' . TrafficMetrics::REFUSED_PREFIX . 'unknown-portal']);
	}//end testDisabledAndUnknownPortalsRefuseWithDistinctReasons()


	/**
	 * A mixed batch stores the good events and counts the bad ones by reason.
	 *
	 * @return void
	 */
	public function testAMixedBatchIsCountedPerReason(): void {
		$service = $this->service(portals: [$this->portal(['enabled' => true, 'events' => ['page_view']])]);

		$result = $service->ingest(
			portalSlug: 'open-tilburg',
			events: [
				['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/'],
				['name' => 'search', 'sequence' => 1, 'pageLocation' => 'https://x/'],
				['name' => 'page_view', 'pageLocation' => 'https://x/'],
				'not an event',
			],
			context: $this->browser()
		);

		$this->assertSame(1, $result['accepted']);
		$this->assertSame(['event-not-enabled' => 1, 'missing-sequence' => 1, 'malformed-event' => 1], $result['refused']);
		$this->assertSame(1, $this->config->values['portaliq/' . TrafficMetrics::ACCEPTED_KEY]);
	}//end testAMixedBatchIsCountedPerReason()


	/**
	 * A server-side mail event is stored, hashed from the contact reference
	 * rather than an address, with its campaign attribution.
	 *
	 * @return void
	 */
	public function testAMailEventIsHashedFromTheContactReference(): void {
		$service = $this->service(portals: [$this->portal([
			'enabled' => true,
			'events' => ['email_open'],
			'dimensions' => ['campaign', 'source', 'medium', 'blastRef', 'contactRef', 'channel'],
		])]);

		$event = [
			'name' => 'email_open',
			'sequence' => 0,
			'pageLocation' => 'mailto:blast/42',
			'campaign' => 'woo-week',
			'source' => 'email',
			'medium' => 'email',
			'blastRef' => '42',
			'contactRef' => 'c-7',
		];
		$result = $service->ingest(portalSlug: 'open-tilburg', events: [$event], context: ['serverSide' => true]);

		$this->assertSame(1, $result['accepted']);
		$record = $this->stored[0];
		$this->assertSame('email', $record['channel']);
		$this->assertSame('woo-week', $record['campaign']);
		$this->assertSame('42', $record['blastRef']);
		$this->assertSame('mailto:blast/42', $record['pagePath']);

		// The same contact hashes the same; a different one does not.
		$service->ingest(portalSlug: 'open-tilburg', events: [$event], context: ['serverSide' => true]);
		$service->ingest(portalSlug: 'open-tilburg', events: [['contactRef' => 'c-8'] + $event], context: ['serverSide' => true]);
		$this->assertSame($this->stored[0]['visitorHash'], $this->stored[1]['visitorHash']);
		$this->assertNotSame($this->stored[0]['visitorHash'], $this->stored[2]['visitorHash']);
	}//end testAMailEventIsHashedFromTheContactReference()


	/**
	 * Two visitors are two hashes; one visitor twice is one.
	 *
	 * @return void
	 */
	public function testTheVisitorHashDistinguishesBrowsers(): void {
		$service = $this->service(portals: [$this->portal()]);
		$event = [['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/']];

		$service->ingest(portalSlug: 'open-tilburg', events: $event, context: $this->browser());
		$service->ingest(portalSlug: 'open-tilburg', events: $event, context: $this->browser());
		$service->ingest(portalSlug: 'open-tilburg', events: $event, context: ['ip' => '198.51.100.4'] + $this->browser());

		$this->assertSame($this->stored[0]['visitorHash'], $this->stored[1]['visitorHash']);
		$this->assertNotSame($this->stored[0]['visitorHash'], $this->stored[2]['visitorHash']);
	}//end testTheVisitorHashDistinguishesBrowsers()


	/**
	 * The subject reference from the context is kept ONLY for a portal
	 * that switched on account linking, and it is the only thing about
	 * the person that is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-account-linking-must-attach-only-a-pseudonymous-reference
	 */
	public function testTheSubjectReferenceIsKeptOnlyWithAccountLinking(): void {
		$event = ['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://open-tilburg.nl/mijn'];
		$context = $this->browser() + ['userRef' => 'subj-42'];

		$linked = $this->service(portals: [$this->portal(['enabled' => true, 'sensitive' => ['accountLinking' => true]])]);
		$linked->ingest(portalSlug: 'open-tilburg', events: [$event], context: $context);
		$this->assertSame('subj-42', $this->stored[0]['userRef']);
		$this->assertStringNotContainsString('203.0.113.9', json_encode($this->stored[0]));

		$this->stored = [];
		$unlinked = $this->service(portals: [$this->portal(['enabled' => true, 'dimensions' => ['userRef']])]);
		$unlinked->ingest(portalSlug: 'open-tilburg', events: [$event], context: $context);
		$this->assertArrayNotHasKey('userRef', $this->stored[0], 'listing the dimension without the switch stores nothing');

		$this->stored = [];
		$linked->ingest(portalSlug: 'open-tilburg', events: [$event], context: $this->browser());
		$this->assertArrayNotHasKey('userRef', $this->stored[0], 'no bearer, no reference, no empty string either');
	}//end testTheSubjectReferenceIsKeptOnlyWithAccountLinking()
}//end class
