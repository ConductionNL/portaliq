<?php

/**
 * TrafficIngestGoldenTest
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

require_once __DIR__ . '/Traffic/FakeAppConfig.php';

/**
 * The stored records, the refusal counts, the metrics and the geo lookups of a traffic batch, per portal, request and event shape.
 *
 * @covers \OCA\Portaliq\Service\TrafficIngestService
 * @uses   \OCA\Portaliq\Service\TrafficConfigResolver
 * @uses   \OCA\Portaliq\Service\TrafficEventValidator
 * @uses   \OCA\Portaliq\Service\Traffic\UserAgentClassifier
 * @uses   \OCA\Portaliq\Service\Traffic\ReferrerClassifier
 * @uses   \OCA\Portaliq\Service\Traffic\VisitorHasher
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeParams
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSegments
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficReportDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions
 */
class TrafficIngestGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/traffic-ingest.json';

	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

	private const BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	/**
	 * Every scenario's trace matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->trace(scenario: $scenario);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: json_decode((string)json_encode($actual[$name]), true), message: $name);
		}
	}//end testEveryScenarioMatchesTheGolden()

	/**
	 * Ingest one batch over recording doubles and the real classifiers.
	 *
	 * @param array<string, mixed> $scenario The portal's traffic block, the events, the request context and the store's answer.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $scenario): array {
		$log      = [];
		$resolver = $this->createMock(originalClassName: PortalResolver::class);
		$resolver->method('allPublishedPortals')->willReturn([
			['slug' => 'noord', 'status' => 'published', 'traffic' => ['enabled' => true]],
			['slug' => 'zuid', 'status' => 'published', 'traffic' => ($scenario['traffic'] ?? ['enabled' => true])],
		]);

		$store = $this->createMock(originalClassName: TrafficEventStore::class);
		$store->method('append')->willReturnCallback(static function (array $records) use (&$log, $scenario): int {
			$log[] = ['append', $records];
			return min(count($records), ($scenario['storeAccepts'] ?? PHP_INT_MAX));
		});

		$metrics = $this->createMock(originalClassName: TrafficMetrics::class);
		$metrics->method('accepted')->willReturnCallback(static function (int $count) use (&$log): void {
			$log[] = ['metrics.accepted', $count];
		});
		$metrics->method('refused')->willReturnCallback(static function (array $reasons) use (&$log): void {
			$log[] = ['metrics.refused', $reasons];
		});

		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getTime')->willReturn(1788000000);
		$clock->method('getDateTime')->willReturn(new DateTime('@1788000000'));

		$geo = $this->createMock(originalClassName: GeoResolverInterface::class);
		$geo->method('resolve')->willReturnCallback(static function (string $address, string $granularity) use (&$log, $scenario): ?string {
			$log[] = ['geo', $address, $granularity];
			return ($scenario['region'] ?? 'NL');
		});

		$appConfig = new FakeAppConfig();
		$appConfig->values['portaliq/' . VisitorHasher::SALT_PREFIX . gmdate('Ymd', 1788000000)] = str_repeat('5a', 32);

		$service = new TrafficIngestService(
			portals: $resolver,
			config: new TrafficConfigResolver(),
			validator: new TrafficEventValidator(),
			store: $store,
			metrics: $metrics,
			hasher: new VisitorHasher(config: $appConfig->mock(test: $this), time: $clock),
			agents: new UserAgentClassifier(),
			referrers: new ReferrerClassifier(),
			geo: $geo,
			time: $clock
		);

		$result = $service->ingest(
			portalSlug: ($scenario['slug'] ?? 'zuid'),
			events: ($scenario['events'] ?? [self::view()]),
			context: ($scenario['context'] ?? self::browser())
		);

		return ['result' => $result, 'log' => $log];
	}//end trace()

	/**
	 * A browser request's context.
	 *
	 * @return array<string, mixed>
	 */
	private static function browser(): array {
		return ['ip' => '203.0.113.9', 'userAgent' => self::CHROME, 'acceptLanguage' => 'nl-NL,nl;q=0.9', 'consent' => true];
	}//end browser()

	/**
	 * A page view.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function view(array $extra = []): array {
		return array_replace([
			'name'         => 'page_view',
			'sequence'     => 0,
			'pageLocation' => 'https://zuid.example/woo?utm_campaign=najaar&utm_source=nieuwsbrief&utm_medium=email',
			'pageReferrer' => 'https://www.google.nl/',
			'pageTitle'    => 'Woo',
		], $extra);
	}//end view()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$all  = [
			'pageReferrer', 'pageTitle', 'deviceType', 'browser', 'os', 'language', 'referrerHost',
			'channel', 'region', 'campaign', 'source', 'medium', 'userRef',
		];
		$wide = ['enabled' => true, 'dimensions' => $all, 'regionGranularity' => 'region', 'sensitive' => ['accountLinking' => true]];
		$mail = [
			'name'         => 'email_open',
			'sequence'     => 0,
			'pageLocation' => 'https://zuid.example/woo?utm_campaign=url-campaign&utm_term=t',
			'campaign'     => 'woo-week',
			'source'       => 'email',
			'medium'       => '',
			'term'         => 'envelope',
			'blastRef'     => '42',
			'contactRef'   => 'c-7',
		];
		$mailTraffic = [
			'enabled'    => true,
			'events'     => ['email_open'],
			'dimensions' => ['campaign', 'source', 'medium', 'term', 'blastRef', 'contactRef', 'channel', 'region'],
		];

		return [
			'unknown portal'             => ['slug' => 'west', 'events' => [self::view(), self::view()]],
			'unknown portal no events'   => ['slug' => 'west', 'events' => []],
			'rollup portal'              => [
				'traffic' => ['enabled' => true, 'rollupOf' => ['noord']],
				'events' => [self::view(), self::view(), self::view()],
			],
			'bot'                        => ['context' => ['userAgent' => self::BOT] + self::browser()],
			'bot server side'            => [
				'traffic' => $mailTraffic,
				'events' => [$mail],
				'context' => ['serverSide' => true, 'userAgent' => self::BOT],
			],
			'disabled portal'            => ['traffic' => ['enabled' => false]],
			'default dimensions'         => [],
			'wide dimensions'            => ['traffic' => $wide, 'context' => ['userRef' => '  acct-1  '] + self::browser()],
			'wide without account link'  => ['traffic' => ['sensitive' => []] + $wide, 'context' => ['userRef' => 'acct-1'] + self::browser()],
			'region none'                => ['traffic' => ['regionGranularity' => 'none'] + $wide],
			'region unknown answer'      => ['traffic' => $wide, 'region' => null],
			'country granularity'        => ['traffic' => ['regionGranularity' => 'country'] + $wide, 'region' => ''],
			'no consent'                 => ['traffic' => $wide, 'context' => ['consent' => false] + self::browser()],
			'consent required, none'     => [
				'traffic' => ['consent' => ['required' => true]] + $wide,
				'context' => ['consent' => false] + self::browser(),
			],
			'malformed and refused'      => [
				'traffic' => $wide,
				'events' => [
					'x',
					self::view(),
					['name' => 'bogus', 'sequence' => 1],
					7,
					self::view(extra: ['sequence' => 2, 'pageLocation' => 'https://zuid.example/a']),
				],
			],
			'excluded paths'             => [
				'traffic' => ['excludedPaths' => ['/melden', '/']] + $wide,
				'events'  => [
					self::view(extra: ['pageLocation' => 'https://zuid.example/melden/x']),
					self::view(extra: ['pageLocation' => 'https://zuid.example/meldenx', 'sequence' => 1]),
					self::view(extra: ['pageLocation' => 'https://zuid.example', 'sequence' => 2]),
				],
			],
			'excluded sub path only'     => [
				'traffic' => ['excludedPaths' => ['/melden']] + $wide,
				'events'  => [
					self::view(extra: ['pageLocation' => 'https://zuid.example/melden/']),
					self::view(extra: ['pageLocation' => 'https://zuid.example/meldingen', 'sequence' => 1]),
				],
			],
			'store keeps some'           => [
				'traffic' => $wide,
				'events' => [self::view(), self::view(extra: ['sequence' => 1]), self::view(extra: ['sequence' => 2])],
				'storeAccepts' => 1,
			],
			'store keeps none'           => ['traffic' => $wide, 'storeAccepts' => 0],
			'retention days'             => ['traffic' => ['retentionDays' => 7] + $wide],
			'mail event server side'     => [
				'traffic' => $mailTraffic,
				'events' => [$mail],
				'context' => ['serverSide' => true, 'ip' => '198.51.100.1'],
			],
			'mail envelope blank medium' => [
				'traffic' => $mailTraffic,
				'events'  => [['pageLocation' => 'https://zuid.example/woo?utm_medium=url-medium&utm_source=url-source'] + $mail],
				'context' => ['serverSide' => true],
			],
			'server side not strictly'   => ['traffic' => $mailTraffic, 'events' => [$mail], 'context' => ['serverSide' => 'yes', 'userAgent' => self::BOT]],
			'mail event from browser'    => ['traffic' => $mailTraffic, 'events' => [$mail], 'context' => self::browser()],
			'two visitors same batch'    => [
				'traffic' => $wide,
				'events' => [self::view(), self::view(extra: ['sequence' => 1, 'pageReferrer' => ''])],
			],
			'language header odd'        => ['traffic' => $wide, 'context' => ['acceptLanguage' => ' EN-gb'] + self::browser()],
			'language header empty'      => ['traffic' => $wide, 'context' => ['acceptLanguage' => '*'] + self::browser()],
			'old event allowed'          => [
				'traffic' => $wide,
				'events' => [self::view(extra: ['timestamp' => '2026-01-01T00:00:00.000Z'])],
				'context' => ['allowOld' => true] + self::browser(),
			],
			'old event refused'          => [
				'traffic' => $wide,
				'events' => [self::view(extra: ['timestamp' => '2026-01-01T00:00:00.000Z'])],
				'context' => ['allowOld' => 'yes'] + self::browser(),
			],
			'internal referrer'          => [
				'traffic' => $wide,
				'events' => [self::view(extra: ['pageReferrer' => 'https://zuid.example/home', 'pageLocation' => 'https://zuid.example/b'])],
			],
		];
	}//end scenarios()
}//end class
