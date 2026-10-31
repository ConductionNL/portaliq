<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\LoopbackOrigins;
use OCA\Portaliq\Tests\Doubles\ServerVariablesRequest;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The origins InstanceLoopback retries on.
 *
 * @spec openspec/changes/loopback-tries-the-address-php-was-reached-on/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-on-the-addresses-this-server-answers-on
 */
class LoopbackOriginsTest extends TestCase {
	/**
	 * The service over these server variables and this overwrite.cli.url.
	 *
	 * @param array<string, mixed> $server The server variables.
	 * @param string $cliUrl The overwrite.cli.url value.
	 *
	 * @return LoopbackOrigins
	 */
	private function origins(array $server, string $cliUrl = ''): LoopbackOrigins {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'overwrite.cli.url' ? $cliUrl : $default
		);

		return new LoopbackOrigins(new ServerVariablesRequest($server), $config);
	}//end origins()

	/**
	 * A web request: the server address and port first, then 127.0.0.1 on
	 * that port, overwrite.cli.url and plain 127.0.0.1.
	 *
	 * @return void
	 */
	public function testAWebRequestTriesTheServerAddressFirst(): void {
		$this->assertSame(
			['http://10.0.0.5:8080', 'http://127.0.0.1:8080', 'https://zuiddrecht.nl', 'http://127.0.0.1'],
			$this->origins(['SERVER_ADDR' => '10.0.0.5', 'SERVER_PORT' => '8080'], 'https://zuiddrecht.nl/')->candidates('http://localhost:8196/index.php/x')
		);
	}//end testAWebRequestTriesTheServerAddressFirst()

	/**
	 * The absolute URL's own origin and duplicates are left out; an IPv6
	 * server address is bracketed; HTTPS on makes the local scheme https.
	 *
	 * @return void
	 */
	public function testTheAbsoluteOriginAndDuplicatesAreLeftOut(): void {
		$this->assertSame(
			['https://[::1]:443', 'https://127.0.0.1:443', 'http://127.0.0.1'],
			$this->origins(['SERVER_ADDR' => '::1', 'SERVER_PORT' => '443', 'HTTPS' => 'on'], 'https://cloud.example')->candidates('https://cloud.example/index.php/x')
		);
		$this->assertSame(
			['http://127.0.0.1'],
			$this->origins(['SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => '80'], 'http://127.0.0.1:80')->candidates('http://127.0.0.1:80/x')
		);
	}//end testTheAbsoluteOriginAndDuplicatesAreLeftOut()

	/**
	 * Cron and occ have no server address: overwrite.cli.url and 127.0.0.1
	 * remain. A non-IP address, a bad port and a non-http CLI URL are ignored.
	 *
	 * @return void
	 */
	public function testWithoutAWebServerOnlyTheConfiguredAndLocalOriginsRemain(): void {
		$this->assertSame(['http://nc.local:8080', 'http://127.0.0.1'], $this->origins([], 'http://NC.local:8080/nextcloud')->candidates('https://cloud.example/x'));
		$this->assertSame(['http://127.0.0.1'], $this->origins(['SERVER_ADDR' => 'web', 'SERVER_PORT' => '99999'], 'ftp://x')->candidates('https://cloud.example/x'));
	}//end testWithoutAWebServerOnlyTheConfiguredAndLocalOriginsRemain()
}//end class
