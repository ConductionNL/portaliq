<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCA\Portaliq\Tests\Unit\Service\Fixtures\FakeConnectException;
use OCA\Portaliq\Tests\Unit\Service\Fixtures\FakeResponseException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * InstanceLoopback: the one way portaliq calls its own instance.
 *
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
 */
class InstanceLoopbackTest extends TestCase {
	/**
	 * Every call the client double received: [method, url, options].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * The client double. Each test sets what it answers.
	 *
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $client;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = [];
		// A plain createMock() doubles whatever IClient declares on the server under
		// test. A hard-coded onlyMethods() list broke on stable32/33, whose
		// IClient has no sendRequest() (only stable34+ extends PSR-18).
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service on an instance whose absolute URL is `$base` + path.
	 *
	 * @param string $configured The internal_base_url app config value.
	 * @param string $base The instance's absolute base URL, web root included.
	 *
	 * @return InstanceLoopback
	 */
	private function loopback(string $configured = '', string $base = 'http://localhost:8090'): InstanceLoopback {
		$clients = $this->getMockBuilder(IClientService::class)->onlyMethods(['newClient'])->getMock();
		$clients->method('newClient')->willReturn($this->client);

		$urls = $this->createMock(IURLGenerator::class);
		$root = (string)parse_url($base, PHP_URL_PATH);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static function (string $path) use ($base, $root): string {
				if ($root !== '' && str_starts_with($path, $root) === true) {
					$path = substr($path, strlen($root));
				}

				return $base . $path;
			}
		);

		$config = $this->getMockBuilder(IAppConfig::class)->disableOriginalConstructor()->getMock();
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'portaliq' && $key === 'internal_base_url') ? $configured : $default
		);

		return new InstanceLoopback($clients, $urls, new InternalBaseUrl($config, $this->logger), $this->logger);
	}//end loopback()

	/**
	 * Make the client answer every method through one callback, recording the call.
	 *
	 * @param callable $answer fn(string $method, string $url, array $options): IResponse.
	 *
	 * @return void
	 */
	private function answer(callable $answer): void {
		foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
			$this->client->method($method)->willReturnCallback(
				function (string $url, array $options = []) use ($method, $answer): IResponse {
					$this->calls[] = [strtoupper($method), $url, $options];
					return $answer(strtoupper($method), $url, $options);
				}
			);
		}
	}//end answer()

	/**
	 * A canned response.
	 *
	 * @param int $status The status.
	 *
	 * @return IResponse
	 */
	private function response(int $status = 200): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn('{}');

		return $response;
	}//end response()

	/**
	 * The absolute URL answers: one call, to that URL, options untouched
	 * except allow_local_address, no Host header added.
	 *
	 * @return void
	 */
	public function testTheAbsoluteUrlIsUsedWhenItAnswers(): void {
		$this->answer(fn () => $this->response(200));
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('warning');

		$response = $this->loopback()->request('GET', '/index.php/apps/openregister/api/portal-tasks?limit=25', ['headers' => ['X-Portal-Subject' => 'jwt']]);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertCount(1, $this->calls);
		$this->assertSame('http://localhost:8090/index.php/apps/openregister/api/portal-tasks?limit=25', $this->calls[0][1]);
		$this->assertSame(['X-Portal-Subject' => 'jwt'], $this->calls[0][2]['headers']);
		$this->assertTrue($this->calls[0][2]['nextcloud']['allow_local_address']);
	}//end testTheAbsoluteUrlIsUsedWhenItAnswers()

	/**
	 * A configured internal base URL wins: the absolute URL is never tried,
	 * the path keeps index.php and the query, and the original Host travels.
	 *
	 * @return void
	 */
	public function testAConfiguredInternalBaseUrlWins(): void {
		$this->answer(fn () => $this->response(200));
		$this->logger->expects($this->never())->method('warning');

		$this->loopback(configured: 'http://nextcloud-app:8080/')->request('POST', '/index.php/apps/x/api/act?a=1', ['headers' => ['X-Portal-Subject' => 'jwt']]);

		$this->assertCount(1, $this->calls);
		$this->assertSame('POST', $this->calls[0][0]);
		$this->assertSame('http://nextcloud-app:8080/index.php/apps/x/api/act?a=1', $this->calls[0][1]);
		$this->assertSame('localhost:8090', $this->calls[0][2]['headers']['Host']);
		$this->assertSame('jwt', $this->calls[0][2]['headers']['X-Portal-Subject']);
		$this->assertArrayNotHasKey('Authorization', $this->calls[0][2]['headers']);
	}//end testAConfiguredInternalBaseUrlWins()

	/**
	 * The configured base URL carries its own web root; the instance's web
	 * root is taken off the path first, so it is not doubled.
	 *
	 * @return void
	 */
	public function testAConfiguredBaseUrlReplacesTheWebRoot(): void {
		$this->answer(fn () => $this->response(200));

		$this->loopback(configured: 'http://app/nc', base: 'https://cloud.example/nextcloud')->request('GET', '/nextcloud/index.php/apps/x');

		$this->assertSame('http://app/nc/index.php/apps/x', $this->calls[0][1]);
		$this->assertSame('cloud.example', $this->calls[0][2]['headers']['Host']);
	}//end testAConfiguredBaseUrlReplacesTheWebRoot()

	/**
	 * The configured address is the administrator's answer: when it fails in
	 * transport the failure surfaces, with no guessing on the loopback.
	 *
	 * @return void
	 */
	public function testAFailingConfiguredAddressIsNotRetried(): void {
		$this->answer(fn () => throw new FakeConnectException('cURL error 7: Failed to connect', ['errno' => 7]));

		try {
			$this->loopback(configured: 'http://nextcloud-app')->request('GET', '/index.php/apps/x');
			$this->fail('The transport failure must surface.');
		} catch (FakeConnectException) {
			$this->assertCount(1, $this->calls);
		}
	}//end testAFailingConfiguredAddressIsNotRetried()

	/**
	 * A connect failure on the absolute URL is retried once on 127.0.0.1,
	 * same path and query, original Host and headers, reported once at info.
	 * The next call in the same request goes straight to the loopback.
	 *
	 * @return void
	 */
	public function testATransportFailureIsRetriedOnceOnTheLoopbackWithTheOriginalHost(): void {
		$this->answer(
			function (string $method, string $url): IResponse {
				if (str_starts_with($url, 'http://localhost:8090') === true) {
					throw new FakeConnectException('cURL error 7: Failed to connect to localhost port 8090', ['errno' => 7, 'connect_time' => 0.0]);
				}

				return $this->response(200);
			}
		);
		$this->logger->expects($this->once())->method('info');
		$this->logger->expects($this->never())->method('warning');

		$loopback = $this->loopback();
		$first = $loopback->request('GET', '/index.php/apps/openregister/api/portal-tasks?limit=25', ['headers' => ['X-Portal-Subject' => 'jwt']]);
		$second = $loopback->request('POST', '/index.php/apps/openregister/api/portal-tasks/u-1/complete');

		$this->assertSame(200, $first->getStatusCode());
		$this->assertSame(200, $second->getStatusCode());
		$this->assertCount(3, $this->calls);
		$this->assertSame('http://127.0.0.1/index.php/apps/openregister/api/portal-tasks?limit=25', $this->calls[1][1]);
		$this->assertSame('localhost:8090', $this->calls[1][2]['headers']['Host']);
		$this->assertSame('jwt', $this->calls[1][2]['headers']['X-Portal-Subject']);
		$this->assertArrayNotHasKey('Authorization', $this->calls[1][2]['headers']);
		$this->assertSame(['POST', 'http://127.0.0.1/index.php/apps/openregister/api/portal-tasks/u-1/complete'], [$this->calls[2][0], $this->calls[2][1]]);
	}//end testATransportFailureIsRetriedOnceOnTheLoopbackWithTheOriginalHost()

	/**
	 * Without a handler context the cURL number in the message decides; an
	 * https URL without a port sends the bare host name.
	 *
	 * @return void
	 */
	public function testTheCurlNumberInTheMessageCountsWithoutAContext(): void {
		$this->answer(
			function (string $method, string $url): IResponse {
				if (str_starts_with($url, 'https://') === true) {
					throw new RuntimeException('cURL error 6: Could not resolve host: cloud.example');
				}

				return $this->response(204);
			}
		);

		$response = $this->loopback(base: 'https://cloud.example')->request('DELETE', '/index.php/apps/x/1');

		$this->assertSame(204, $response->getStatusCode());
		$this->assertSame(['DELETE', 'http://127.0.0.1/index.php/apps/x/1'], [$this->calls[1][0], $this->calls[1][1]]);
		$this->assertSame('cloud.example', $this->calls[1][2]['headers']['Host']);
	}//end testTheCurlNumberInTheMessageCountsWithoutAContext()

	/**
	 * The handler context decides when it carries the cURL number, whatever
	 * the message says.
	 *
	 * @return void
	 */
	public function testTheHandlerContextNumberCounts(): void {
		$this->answer(
			function (string $method, string $url): IResponse {
				if (str_starts_with($url, 'http://localhost:8090') === true) {
					throw new FakeConnectException('Connection refused', ['errno' => 7]);
				}

				return $this->response(200);
			}
		);

		$this->assertSame(200, $this->loopback()->request('GET', '/index.php/apps/x')->getStatusCode());
		$this->assertCount(2, $this->calls);
	}//end testTheHandlerContextNumberCounts()

	/**
	 * A timeout before the connection is a transport failure; a timeout after
	 * it may have applied the request, so it is never retried.
	 *
	 * @return void
	 */
	public function testOnlyATimeoutBeforeTheConnectionIsRetried(): void {
		$this->answer(fn () => throw new FakeConnectException('cURL error 28: Connection timed out after 5001 milliseconds', ['errno' => 28, 'connect_time' => 0.0]));
		try {
			$this->loopback()->request('GET', '/index.php/apps/x');
		} catch (FakeConnectException) {
		}

		$this->assertCount(2, $this->calls, 'A connect timeout gets one loopback retry.');

		$this->setUp();
		$this->answer(fn () => throw new FakeConnectException('cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received', ['errno' => 28, 'connect_time' => 0.004]));
		try {
			$this->loopback()->request('POST', '/index.php/apps/x');
		} catch (FakeConnectException) {
		}

		$this->assertCount(1, $this->calls, 'A timeout after the connection is never retried.');

		$this->setUp();
		$this->answer(fn () => throw new RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds'));
		try {
			$this->loopback()->request('GET', '/index.php/apps/x');
		} catch (RuntimeException) {
		}

		$this->assertCount(1, $this->calls, 'A timeout the message does not place before the connection is not retried.');
	}//end testOnlyATimeoutBeforeTheConnectionIsRetried()

	/**
	 * When both addresses fail: one warning naming both, and the ORIGINAL
	 * failure surfaces. Nothing is remembered, so the next call tries the
	 * absolute URL again.
	 *
	 * @return void
	 */
	public function testWhenBothFailTheOriginalFailureSurfacesWithAWarning(): void {
		$original = new FakeConnectException('cURL error 7: absolute', ['errno' => 7]);
		$this->answer(
			function (string $method, string $url) use ($original): IResponse {
				if (str_starts_with($url, 'http://127.0.0.1') === true) {
					throw new FakeConnectException('cURL error 7: loopback', ['errno' => 7]);
				}

				throw $original;
			}
		);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('nor on the loopback'),
			$this->callback(static fn (array $context): bool => $context['absolute'] === 'cURL error 7: absolute' && $context['loopback'] === 'cURL error 7: loopback')
		);

		try {
			$this->loopback()->request('GET', '/index.php/apps/x');
			$this->fail('The failure must surface.');
		} catch (FakeConnectException $surfaced) {
			$this->assertSame($original, $surfaced);
		}

		$this->assertCount(2, $this->calls);
	}//end testWhenBothFailTheOriginalFailureSurfacesWithAWarning()

	/**
	 * Any HTTP answer is final: a 4xx or 5xx comes back as is, with no retry.
	 *
	 * @return void
	 */
	public function testAnHttpErrorStatusIsNeverRetried(): void {
		foreach ([401, 404, 500, 502] as $status) {
			$this->setUp();
			$this->answer(fn () => $this->response($status));

			$response = $this->loopback()->request('GET', '/index.php/apps/x');

			$this->assertSame($status, $response->getStatusCode());
			$this->assertCount(1, $this->calls, 'HTTP ' . $status . ' must not be retried.');
		}
	}//end testAnHttpErrorStatusIsNeverRetried()

	/**
	 * A failure that carries a response (http_errors on) is an HTTP answer,
	 * whatever its message says, and is rethrown without a retry.
	 *
	 * @return void
	 */
	public function testAFailureCarryingAResponseIsNeverRetried(): void {
		$this->answer(fn () => throw new FakeResponseException($this->response(503)));

		try {
			$this->loopback()->request('GET', '/index.php/apps/x');
			$this->fail('The failure must surface.');
		} catch (FakeResponseException) {
			$this->assertCount(1, $this->calls);
		}
	}//end testAFailureCarryingAResponseIsNeverRetried()

	/**
	 * Any other failure (not a connect-phase cURL error) is not retried.
	 *
	 * @return void
	 */
	public function testAFailureThatIsNotTransportIsNeverRetried(): void {
		$this->answer(fn () => throw new RuntimeException('connection refused'));

		try {
			$this->loopback()->request('GET', '/index.php/apps/x');
			$this->fail('The failure must surface.');
		} catch (RuntimeException) {
			$this->assertCount(1, $this->calls);
		}
	}//end testAFailureThatIsNotTransportIsNeverRetried()

	/**
	 * An invalid configured value is ignored with one warning per request;
	 * the absolute URL is used as if nothing were set.
	 *
	 * @return void
	 */
	public function testAnInvalidConfiguredUrlIsIgnoredWithAWarning(): void {
		$this->answer(fn () => $this->response(200));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('invalid internal_base_url'));

		$loopback = $this->loopback(configured: 'http://nextcloud/../etc');
		$loopback->request('GET', '/index.php/apps/x');
		$loopback->request('GET', '/index.php/apps/y');

		$this->assertSame('http://localhost:8090/index.php/apps/x', $this->calls[0][1]);
		$this->assertSame('http://localhost:8090/index.php/apps/y', $this->calls[1][1]);
		$this->assertArrayNotHasKey('headers', $this->calls[0][2]);
	}//end testAnInvalidConfiguredUrlIsIgnoredWithAWarning()

	/**
	 * Each HTTP method reaches the client method of the same name; anything
	 * else is a POST, as the action forwarder always treated it.
	 *
	 * @return void
	 */
	public function testEachMethodReachesItsClientMethod(): void {
		$this->answer(fn () => $this->response(200));
		$loopback = $this->loopback();

		foreach (['get', 'POST', 'Put', 'patch', 'DELETE', 'SOMETHING'] as $method) {
			$loopback->request($method, '/index.php/apps/x');
		}

		$this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'POST'], array_column($this->calls, 0));
	}//end testEachMethodReachesItsClientMethod()

	/**
	 * A multipart upload the first attempt started reading is rewound, so
	 * the loopback retry sends the whole file.
	 *
	 * @return void
	 */
	public function testAnUploadStreamIsRewoundForTheRetry(): void {
		$file = fopen('php://temp', 'w+b');
		fwrite($file, 'file-bytes');
		rewind($file);
		$positions = [];
		$this->answer(
			function (string $method, string $url, array $options) use (&$positions): IResponse {
				$handle = $options['multipart'][0]['contents'];
				$positions[] = ftell($handle);
				stream_get_contents($handle);
				if (str_starts_with($url, 'http://localhost:8090') === true) {
					throw new FakeConnectException('cURL error 7: refused', ['errno' => 7]);
				}

				return $this->response(200);
			}
		);

		$this->loopback()->request('POST', '/index.php/apps/x', ['multipart' => [['name' => 'files[]', 'contents' => $file]]]);

		$this->assertSame([0, 0], $positions);
		fclose($file);
	}//end testAnUploadStreamIsRewoundForTheRetry()
}//end class
