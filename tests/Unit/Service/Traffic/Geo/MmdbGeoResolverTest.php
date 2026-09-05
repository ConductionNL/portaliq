<?php

/**
 * Unit tests for MmdbGeoResolver.
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic\Geo;

use OCA\Portaliq\Service\Traffic\Geo\GeoDatabaseStore;
use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use OCA\Portaliq\Service\Traffic\Geo\MmdbGeoResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The lookup against MaxMind's test database (tests/fixtures, Apache-2.0),
 * which maps a handful of documented addresses and nothing else.
 */
class MmdbGeoResolverTest extends TestCase {

	/**
	 * The fixture database.
	 */
	private const FIXTURE = __DIR__ . '/../../../../fixtures/GeoIP2-City-Test.mmdb';

	/**
	 * A resolver over a store that holds the fixture, or nothing.
	 *
	 * @param string|null            $path     What the store answers for the database path.
	 * @param string                 $provider The configured provider.
	 * @param GeoRefreshService|null $refresh  The refresh double, when a test watches it.
	 *
	 * @return MmdbGeoResolver The resolver.
	 */
	private function resolver(?string $path = self::FIXTURE, string $provider = 'dbip', ?GeoRefreshService $refresh = null): MmdbGeoResolver {
		$settings = $this->createMock(GeoSettings::class);
		$settings->method('provider')->willReturn($provider);

		$store = $this->createMock(GeoDatabaseStore::class);
		$store->method('databasePath')->willReturn($path);

		return new MmdbGeoResolver(
			$settings,
			$store,
			($refresh ?? $this->createMock(GeoRefreshService::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end resolver()


	/**
	 * Country granularity gives the ISO 3166-1 alpha-2 code and nothing
	 * finer, whatever the database knows.
	 *
	 * @return void
	 */
	public function testCountryGranularityGivesTheCountryOnly(): void {
		$resolver = $this->resolver();

		$this->assertSame('GB', $resolver->resolve(address: '81.2.69.160', granularity: 'country'));
		$this->assertSame('SE', $resolver->resolve(address: '89.160.20.128', granularity: 'country'));
		$this->assertSame('US', $resolver->resolve(address: '216.160.83.56', granularity: 'country'));
	}//end testCountryGranularityGivesTheCountryOnly()


	/**
	 * Region granularity gives country-subdivision, and the country alone
	 * when the database has no subdivision.
	 *
	 * @return void
	 */
	public function testRegionGranularityGivesTheSubdivision(): void {
		$resolver = $this->resolver();

		$this->assertSame('GB-ENG', $resolver->resolve(address: '81.2.69.160', granularity: 'region'));
		$this->assertSame('SE-E', $resolver->resolve(address: '89.160.20.128', granularity: 'region'));
		$this->assertSame('US-WA', $resolver->resolve(address: '216.160.83.56', granularity: 'region'));
	}//end testRegionGranularityGivesTheSubdivision()


	/**
	 * An address the database does not know, a private address, a malformed
	 * address and granularity none all resolve to nothing, without an error.
	 *
	 * @return void
	 */
	public function testUnknownAddressesAndGranularityNoneResolveNothing(): void {
		$resolver = $this->resolver();

		$this->assertNull($resolver->resolve(address: '203.0.113.9', granularity: 'country'));
		$this->assertNull($resolver->resolve(address: '10.0.0.1', granularity: 'region'));
		$this->assertNull($resolver->resolve(address: 'not-an-address', granularity: 'country'));
		$this->assertNull($resolver->resolve(address: '', granularity: 'country'));
		$this->assertNull($resolver->resolve(address: '81.2.69.160', granularity: 'none'));
	}//end testUnknownAddressesAndGranularityNoneResolveNothing()


	/**
	 * Provider none is geography off for every portal, even with a database
	 * on disk.
	 *
	 * @return void
	 */
	public function testProviderNoneResolvesNothing(): void {
		$this->assertNull($this->resolver(provider: 'none')->resolve(address: '81.2.69.160', granularity: 'country'));
	}//end testProviderNoneResolvesNothing()


	/**
	 * With no database the resolver queues the first download ONCE per
	 * process and answers null: nothing downloads inside the request.
	 *
	 * @return void
	 */
	public function testAnAbsentDatabaseQueuesTheFirstDownloadOnceAndResolvesNothing(): void {
		$refresh = $this->createMock(GeoRefreshService::class);
		$refresh->expects($this->once())->method('queueFirstDownload')->willReturn(true);

		$resolver = $this->resolver(path: null, refresh: $refresh);
		$this->assertNull($resolver->resolve(address: '81.2.69.160', granularity: 'country'));
		$this->assertNull($resolver->resolve(address: '89.160.20.128', granularity: 'country'));
	}//end testAnAbsentDatabaseQueuesTheFirstDownloadOnceAndResolvesNothing()


	/**
	 * A file that is not a database is logged and resolves nothing, rather
	 * than failing the collector.
	 *
	 * @return void
	 */
	public function testAFileThatIsNotADatabaseResolvesNothing(): void {
		$garbage = tempnam(sys_get_temp_dir(), 'notmmdb');
		file_put_contents($garbage, 'this is not a database');
		try {
			$this->assertNull($this->resolver(path: $garbage)->resolve(address: '81.2.69.160', granularity: 'country'));
		} finally {
			unlink($garbage);
		}
	}//end testAFileThatIsNotADatabaseResolvesNothing()
}//end class
