<?php

/**
 * Unit tests for the portaliq:traffic:geo-refresh command.
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

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\TrafficGeoRefresh;
use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The exit code is the contract: 0 for refreshed AND for disabled, 1 for
 * failed.
 */
class TrafficGeoRefreshTest extends TestCase {

	/**
	 * Run the command over a service double.
	 *
	 * @param array<string, string> $result  What refresh() answers.
	 * @param array<string, mixed>  $status  What status() answers.
	 *
	 * @return array{0: int, 1: string} Exit code and output.
	 */
	private function runCommand(array $result, array $status): array {
		$service = $this->createMock(GeoRefreshService::class);
		$service->method('refresh')->willReturn($result);
		$service->method('status')->willReturn($status);

		$output = new BufferedOutput();
		$code = (new TrafficGeoRefresh($service))->run(new ArrayInput([]), $output);

		return [$code, $output->fetch()];
	}//end runCommand()


	/**
	 * Provider none: says geography is disabled, exits 0.
	 *
	 * @return void
	 */
	public function testDisabledSaysSoAndExitsZero(): void {
		[$code, $text] = $this->runCommand(
			['status' => 'disabled', 'provider' => 'none', 'message' => 'Geography is disabled: the provider is set to none.'],
			['provider' => 'none', 'present' => false, 'path' => null, 'metadata' => []]
		);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Geography is disabled', $text);
		$this->assertStringContainsString('Database: none installed', $text);
	}//end testDisabledSaysSoAndExitsZero()


	/**
	 * A refresh prints the provenance and exits 0; a failure exits 1.
	 *
	 * @return void
	 */
	public function testRefreshedExitsZeroAndFailedExitsOne(): void {
		[$code, $text] = $this->runCommand(
			['status' => 'refreshed', 'provider' => 'dbip', 'message' => 'Fetched x.'],
			['provider' => 'dbip', 'present' => true, 'path' => '/data/geo/traffic-geo.mmdb', 'metadata' => ['fetchedAt' => '2026-09-04T12:00:00Z', 'databaseType' => 'DBIP-City-Lite', 'attribution' => 'IP Geolocation by DB-IP']]
		);
		$this->assertSame(0, $code);
		$this->assertStringContainsString('/data/geo/traffic-geo.mmdb', $text);
		$this->assertStringContainsString('IP Geolocation by DB-IP', $text);

		[$code, $text] = $this->runCommand(
			['status' => 'failed', 'provider' => 'maxmind', 'message' => 'Refresh from maxmind failed: 401.'],
			['provider' => 'maxmind', 'present' => false, 'path' => null, 'metadata' => []]
		);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('401', $text);
	}//end testRefreshedExitsZeroAndFailedExitsOne()
}//end class
