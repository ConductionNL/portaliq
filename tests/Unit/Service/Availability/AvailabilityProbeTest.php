<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Availability;

use OCA\Portaliq\Service\Availability\AvailabilityProbe;
use OCA\Portaliq\Service\Availability\AvailabilityRollup;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Tests\Unit\Service\Fixtures\FakeConnectException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The availability probe calls this instance through InstanceLoopback, so a
 * public address the server cannot reach from inside does not report every
 * portal down.
 *
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
 */
class AvailabilityProbeTest extends TestCase {
	/**
	 * Behind a port mapping the public address refuses; the probe still
	 * reaches the site and the health check on the loopback, and reports
	 * the portal available.
	 *
	 * @return void
	 */
	public function testAnUnreachablePublicAddressStillProbesThroughTheLoopback(): void {
		$seen = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use (&$seen): IResponse {
				$seen[] = $url;
				if (str_starts_with($url, 'http://localhost:8090') === true) {
					throw new FakeConnectException('cURL error 7: Failed to connect to localhost port 8090', ['errno' => 7]);
				}

				$this->assertSame('localhost:8090', $options['headers']['Host']);
				$this->assertSame(5, $options['timeout']);
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn('{"status":"ok"}');

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $arguments = []): string => match ($route) {
				'portaliq.content.site' => '/index.php/apps/portaliq/api/content/site?portal=' . ($arguments['portal'] ?? ''),
				default => '/index.php/apps/portaliq/api/health',
			}
		);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'http://localhost:8090' . $path);

		$probe = new AvailabilityProbe(
			new InstanceLoopback($clients, $urls, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class)),
			$urls
		);

		$this->assertSame(['status' => AvailabilityRollup::AVAILABLE, 'cause' => ''], $probe->check(slug: 'wilgenboom'));
		$this->assertSame(
			[
				'http://localhost:8090/index.php/apps/portaliq/api/content/site?portal=wilgenboom',
				'http://127.0.0.1/index.php/apps/portaliq/api/content/site?portal=wilgenboom',
				'http://127.0.0.1/index.php/apps/portaliq/api/health',
			],
			$seen
		);
	}//end testAnUnreachablePublicAddressStillProbesThroughTheLoopback()
}//end class
