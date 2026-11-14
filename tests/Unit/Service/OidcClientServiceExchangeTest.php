<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Connection\ConnectionReporter;
use OCA\Portaliq\Service\OidcClientService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The OIDC code exchange: the form it posts, how every broker answer maps
 * to tokens or a closed null, and what is reported to the connection
 * registry; plus the state token and the PKCE pair.
 *
 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T03
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
 */
#[CoversClass(OidcClientService::class)]
final class OidcClientServiceExchangeTest extends TestCase {
	private const TOKEN_ENDPOINT = 'https://broker.example/oauth/token';

	/**
	 * What the reporter was told, as [httpStatus, oauthError, hasToken].
	 *
	 * @var array<int, array{0: int|null, 1: string, 2: bool}>
	 */
	private array $reported = [];

	/**
	 * The service over an HTTP client that answers $answer, or throws when $answer is a Throwable.
	 *
	 * @param IResponse|\Throwable $answer   The token endpoint's answer.
	 * @param array|null           $posted   Receives the post's URL and options.
	 * @param bool                 $reporter Whether a connection reporter is wired.
	 *
	 * @return OidcClientService
	 */
	private function service(IResponse|\Throwable $answer, ?array &$posted = null, bool $reporter = true): OidcClientService {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			static function (string $url, array $options) use ($answer, &$posted): IResponse {
				$posted = ['url' => $url, 'options' => $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$connectionReporter = null;
		if ($reporter === true) {
			$connectionReporter = $this->createMock(ConnectionReporter::class);
			$connectionReporter->method('oidcExchangeAnswered')->willReturnCallback(
				function (string $tokenEndpoint, ?int $httpStatus, string $oauthError, bool $hasToken): bool {
					$this->assertSame(self::TOKEN_ENDPOINT, $tokenEndpoint);
					$this->reported[] = [$httpStatus, $oauthError, $hasToken];
					return true;
				}
			);
		}

		return new OidcClientService(
			$clientService,
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class),
			$connectionReporter
		);

	}//end service()

	/**
	 * A response double.
	 *
	 * @param int   $status The HTTP status.
	 * @param mixed $body   The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;

	}//end response()

	/**
	 * Exchange a code with fixed arguments.
	 *
	 * @param OidcClientService $service The service.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function exchange(OidcClientService $service): ?array {
		return $service->exchangeCode(self::TOKEN_ENDPOINT, 'the-code', 'the-verifier', 'portal-client', 'portal-secret', 'https://portal.example/callback');

	}//end exchange()

	public function testASuccessfulExchangePostsTheAuthorizationCodeGrantAndAnswersTheTokens(): void {
		$posted = null;
		$tokens = ['id_token' => 'eyJ.x.y', 'access_token' => 'at', 'token_type' => 'Bearer'];
		$service = $this->service($this->response(200, json_encode($tokens)), $posted);

		$this->assertSame($tokens, self::exchange($service));
		$this->assertSame(self::TOKEN_ENDPOINT, $posted['url']);
		$this->assertSame(
			[
				'grant_type' => 'authorization_code',
				'code' => 'the-code',
				'redirect_uri' => 'https://portal.example/callback',
				'client_id' => 'portal-client',
				'client_secret' => 'portal-secret',
				'code_verifier' => 'the-verifier',
			],
			$posted['options']['body']
		);
		$this->assertFalse($posted['options']['http_errors']);
		$this->assertGreaterThan(0, $posted['options']['timeout']);
		$this->assertSame([[200, '', true]], $this->reported);
	}//end testASuccessfulExchangePostsTheAuthorizationCodeGrantAndAnswersTheTokens()

	public function testAnErrorAnswerFailsClosedAndReportsTheOauthError(): void {
		$service = $this->service($this->response(400, json_encode(['error' => 'invalid_client', 'error_description' => 'secret wrong'])));

		$this->assertNull(self::exchange($service));
		$this->assertSame([[400, 'invalid_client', false]], $this->reported);
	}//end testAnErrorAnswerFailsClosedAndReportsTheOauthError()

	public function testARedirectStatusIsNotASuccess(): void {
		$service = $this->service($this->response(302, json_encode(['id_token' => 'eyJ.x.y'])));

		$this->assertNull(self::exchange($service));
		$this->assertSame([[302, '', true]], $this->reported);
	}//end testARedirectStatusIsNotASuccess()

	public function testABodyThatIsNotJsonAnswersNullEvenOnSuccess(): void {
		$service = $this->service($this->response(200, '<html>maintenance</html>'));

		$this->assertNull(self::exchange($service));
		$this->assertSame([[200, '', false]], $this->reported);
	}//end testABodyThatIsNotJsonAnswersNullEvenOnSuccess()

	public function testAnEmptyIdTokenOrANonStringErrorReportsNoTokenAndNoError(): void {
		$service = $this->service($this->response(200, json_encode(['id_token' => '', 'error' => ['nested']])));

		$this->assertSame(['id_token' => '', 'error' => ['nested']], self::exchange($service));
		$this->assertSame([[200, '', false]], $this->reported);
	}//end testAnEmptyIdTokenOrANonStringErrorReportsNoTokenAndNoError()

	public function testAnUnreadableBodyAnswersNull(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willThrowException(new RuntimeException('stream closed'));

		$this->assertNull(self::exchange($this->service($response)));
		$this->assertSame([[200, '', false]], $this->reported);
	}//end testAnUnreadableBodyAnswersNull()

	public function testATransportFailureFailsClosedAndIsReportedWithoutAStatus(): void {
		$service = $this->service(new RuntimeException('connection refused'));

		$this->assertNull(self::exchange($service));
		$this->assertSame([[null, '', false]], $this->reported);
	}//end testATransportFailureFailsClosedAndIsReportedWithoutAStatus()

	public function testWithoutAReporterTheExchangeStillWorks(): void {
		$service = $this->service($this->response(200, json_encode(['id_token' => 'eyJ.x.y'])), $posted, false);

		$this->assertSame(['id_token' => 'eyJ.x.y'], self::exchange($service));
		$this->assertNull(self::exchange($this->service(new RuntimeException('down'), $posted, false)));
		$this->assertSame([], $this->reported);
	}//end testWithoutAReporterTheExchangeStillWorks()

	public function testTheStateTokenIsUrlSafeAndFresh(): void {
		$service = $this->service($this->response(200, ''));

		$a = $service->generateToken();
		$b = $service->generateToken();

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
		$this->assertNotSame($a, $b);
	}//end testTheStateTokenIsUrlSafeAndFresh()

	public function testThePkceChallengeIsTheS256OfTheVerifier(): void {
		$pkce = $this->service($this->response(200, ''))->generatePkce();

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $pkce['verifier']);
		$expected = rtrim(strtr(base64_encode(hash('sha256', $pkce['verifier'], true)), '+/', '-_'), '=');
		$this->assertSame($expected, $pkce['challenge']);
	}//end testThePkceChallengeIsTheS256OfTheVerifier()
}//end class
