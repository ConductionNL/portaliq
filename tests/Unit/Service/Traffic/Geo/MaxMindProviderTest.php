<?php

/**
 * Unit tests for MaxMindProvider.
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

use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use OCA\Portaliq\Service\Traffic\Geo\MaxMindProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/GzipExtractorTest.php';

/**
 * The credentials travel as basic auth on the one request; the database
 * comes out of the tarball.
 */
class MaxMindProviderTest extends TestCase {

	/**
	 * The options the fake client received.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()


	/**
	 * A provider with the given credentials and a client that answers
	 * with the given tarball.
	 *
	 * @param string $accountId The account id.
	 * @param string $key       The licence key.
	 * @param string $edition   The edition.
	 * @param string $body      What the download answers with.
	 * @param int    $status    The HTTP status.
	 *
	 * @return MaxMindProvider The provider.
	 */
	private function provider(string $accountId, string $key, string $edition = 'GeoLite2-City', string $body = '', int $status = 200): MaxMindProvider {
		$settings = $this->createMock(GeoSettings::class);
		$settings->method('maxMindAccountId')->willReturn($accountId);
		$settings->method('maxMindLicenseKey')->willReturn($key);
		$settings->method('maxMindEdition')->willReturn($edition);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($body, $status): IResponse {
				$this->options = $options + ['url' => $url];
				file_put_contents($options['sink'], $body);
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'mm');
				$this->files[] = $path;

				return $path;
			}
		);

		return new MaxMindProvider($clients, $temp, $settings);
	}//end provider()


	/**
	 * Without credentials nothing is requested, and the refusal says what
	 * is missing.
	 *
	 * @return void
	 */
	public function testMissingCredentialsAreRefusedBeforeAnyRequest(): void {
		$target = (string)tempnam(sys_get_temp_dir(), 'out');
		$this->files[] = $target;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('licence key');
		$this->provider('123', '')->fetch(targetPath: $target);
	}//end testMissingCredentialsAreRefusedBeforeAnyRequest()


	/**
	 * The edition's URL, basic auth from the settings, and the .mmdb
	 * member extracted to the target.
	 *
	 * @return void
	 */
	public function testTheDatabaseIsPulledOutOfTheTarballWithBasicAuth(): void {
		$tar = GzipExtractorTest::tarMember('GeoIP2-City_20260901/COPYRIGHT.txt', 'c')
			. GzipExtractorTest::tarMember('GeoIP2-City_20260901/GeoIP2-City.mmdb', 'the database')
			. str_repeat("\0", 1024);
		$provider = $this->provider('123456', 'k3y', 'GeoIP2-City', (string)gzencode($tar));
		$target = (string)tempnam(sys_get_temp_dir(), 'out');
		$this->files[] = $target;

		$source = $provider->fetch(targetPath: $target);

		$this->assertSame('https://download.maxmind.com/geoip/databases/GeoIP2-City/download?suffix=tar.gz', $this->options['url']);
		$this->assertSame('Basic ' . base64_encode('123456:k3y'), $this->options['headers']['Authorization']);
		$this->assertSame('the database', file_get_contents($target));
		$this->assertSame('GeoIP2-City (GeoIP2-City_20260901/GeoIP2-City.mmdb)', $source);
		$this->assertStringContainsString('GeoIP2-City', $provider->attribution());
		$this->assertStringContainsString('maxmind.com', $provider->attribution());
	}//end testTheDatabaseIsPulledOutOfTheTarballWithBasicAuth()


	/**
	 * A 401 (wrong key) is a refusal naming the status, not a tarball
	 * parse of the error page.
	 *
	 * @return void
	 */
	public function testARejectedLoginIsARefusal(): void {
		$target = (string)tempnam(sys_get_temp_dir(), 'out');
		$this->files[] = $target;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('401');
		$this->provider('123456', 'wrong', 'GeoLite2-City', '<html>denied</html>', 401)->fetch(targetPath: $target);
	}//end testARejectedLoginIsARefusal()
}//end class
