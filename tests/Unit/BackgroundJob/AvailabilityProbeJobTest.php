<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Portaliq\BackgroundJob\AvailabilityProbeJob;
use OCA\Portaliq\Service\Availability\AvailabilityProbe;
use OCA\Portaliq\Service\Availability\AvailabilityRollup;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * operate-availability-report REQ-OAR-001 and REQ-OAR-003: every published
 * portal is checked through the instance's own URL and recorded as
 * available, degraded or down, and records past thirteen months go.
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */
class AvailabilityProbeJobTest extends TestCase {
	/**
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	private ?string $purgedBefore = null;

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $olderDays = [];

	public function testSiteErrorIsDown(): void {
		$this->runJob(site: 500, health: 'ok');

		$daily = $this->savedOf(AvailabilityStore::DAILY_SCHEMA)[0];
		$this->assertSame(['open-tilburg', '2026-09-29', 1, 0, 0, 1, 0], [$daily['portal'], $daily['date'], $daily['intervals'], $daily['available'], $daily['degraded'], $daily['down'], $daily['noCheck']]);
		$outage = $this->savedOf(AvailabilityStore::OUTAGE_SCHEMA)[0];
		$this->assertSame('site-error', $outage['cause']);
		$this->assertArrayNotHasKey('endedAt', $outage);
	}//end testSiteErrorIsDown()

	public function testDegradedHealthIsDegraded(): void {
		$this->runJob(site: 200, health: 'degraded');

		$this->assertSame(1, $this->savedOf(AvailabilityStore::DAILY_SCHEMA)[0]['degraded']);
		$this->assertSame('health-degraded', $this->savedOf(AvailabilityStore::OUTAGE_SCHEMA)[0]['cause']);
	}//end testDegradedHealthIsDegraded()

	public function testTimeoutIsDown(): void {
		$this->runJob(site: null, health: 'ok');

		$this->assertSame(1, $this->savedOf(AvailabilityStore::DAILY_SCHEMA)[0]['down']);
		$this->assertSame('timeout', $this->savedOf(AvailabilityStore::OUTAGE_SCHEMA)[0]['cause']);
	}//end testTimeoutIsDown()

	public function testAnAvailablePortalOpensNoOutage(): void {
		$this->runJob(site: 200, health: 'ok');

		$this->assertSame(1, $this->savedOf(AvailabilityStore::DAILY_SCHEMA)[0]['available']);
		$this->assertSame([], $this->savedOf(AvailabilityStore::OUTAGE_SCHEMA));
	}//end testAnAvailablePortalOpensNoOutage()

	/**
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-thirteen-months-are-kept-and-no-more-req-oar-003
	 */
	public function testRecordsOlderThanThirteenMonthsAreDeleted(): void {
		$this->runJob(site: 200, health: 'ok');

		$this->assertSame('2025-08-29', $this->purgedBefore);
	}//end testRecordsOlderThanThirteenMonthsAreDeleted()

	/**
	 * An instance that was off for days still counts the gap: with no check
	 * in the last two days, the whole kept window is read.
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
	 */
	public function testAGapOfDaysIsCounted(): void {
		$this->olderDays = ['2026-09-26' => ['uuid' => 'd-26', 'portal' => 'open-tilburg', 'date' => '2026-09-26', 'intervals' => 288, 'available' => 288, 'degraded' => 0, 'down' => 0, 'noCheck' => 0, 'lastCheckAt' => '2026-09-26T23:55:00+00:00']];

		$this->runJob(site: 200, health: 'ok');

		$byDate = array_column($this->savedOf(AvailabilityStore::DAILY_SCHEMA), null, 'date');
		$this->assertSame(288, $byDate['2026-09-27']['noCheck']);
		$this->assertSame(288, $byDate['2026-09-28']['noCheck']);
		$this->assertSame(120, $byDate['2026-09-29']['noCheck']);
		$this->assertArrayNotHasKey('2026-09-26', $byDate);
	}//end testAGapOfDaysIsCounted()

	/**
	 * The saved records of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedOf(string $schema): array {
		return array_values(array_map(static fn (array $entry): array => $entry[1], array_filter($this->saved, static fn (array $entry): bool => $entry[0] === $schema)));
	}//end savedOf()

	/**
	 * Run the job once at 2026-09-29 10:00 UTC over one published portal.
	 *
	 * @param int|null $site The site route's status, or null for a timeout.
	 * @param string $health What the health check says.
	 *
	 * @return void
	 */
	private function runJob(?int $site, string $health): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-29T10:00:00+00:00'));

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'open-tilburg'], ['title' => 'no slug']]);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options) use ($site, $health): IResponse {
				$this->assertSame(5, $options['timeout']);
				$this->assertTrue($options['nextcloud']['allow_local_address']);
				if (str_contains($uri, '/api/content/site') === true) {
					if ($site === null) {
						throw new RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds');
					}

					return $this->response(status: $site, body: '{}');
				}

				return $this->response(status: 200, body: (string)json_encode(['status' => $health]));
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $arguments = []): string => match ($route) {
				'portaliq.content.site' => 'https://cloud.example/index.php/apps/portaliq/api/content/site?portal=' . ($arguments['portal'] ?? ''),
				'portaliq.health.index' => 'https://cloud.example/index.php/apps/portaliq/api/health',
				default => 'https://cloud.example/unknown',
			}
		);

		$store = $this->getMockBuilder(AvailabilityStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['dailyBetween', 'openOutage', 'save', 'purgeBefore'])
			->getMock();
		$store->method('dailyBetween')->willReturnCallback(
			fn (string $portal, string $from, string $until): array => ($from === '2026-09-28' ? [] : $this->olderDays)
		);
		$store->method('openOutage')->willReturn(null);
		$store->method('save')->willReturnCallback(
			function (string $schema, array $row): bool {
				$this->saved[] = [$schema, $row];
				return true;
			}
		);
		$store->method('purgeBefore')->willReturnCallback(
			function (string $cutoff): int {
				$this->purgedBefore = $cutoff;
				return 0;
			}
		);

		$job = new AvailabilityProbeJob(
			$time,
			$portals,
			new AvailabilityProbe($clients, $urls),
			$store,
			new AvailabilityRollup(),
			$this->createMock(LoggerInterface::class)
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * A response double.
	 *
	 * @param int $status The status.
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()
}//end class
