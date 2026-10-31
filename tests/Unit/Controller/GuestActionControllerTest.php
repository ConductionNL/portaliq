<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\GuestActionRegistry;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Controller\GuestActionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guest routes of identity-guest-page-for-signed-links: a declared guest
 * action is forwarded with the token stamped under its `tokenField` and a
 * guest assertion, an unknown one is a 404 with nothing forwarded, and the
 * audit keeps only the token's hash. Built on the real registry, forwarder
 * and session service; only the provider, the HTTP client, the portal
 * lookup and the audit store are doubles.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T02
 */
class GuestActionControllerTest extends TestCase {

	private const PROVIDER_FQCN = 'OCA\\Portaliq\\Portal\\PortalContributionProvider';

	private const ACTIONS = [
		[
			'id' => 'withdraw',
			'guest' => true,
			'endpoint' => '/apps/portaliq/api/test/withdraw',
			'previewEndpoint' => '/apps/portaliq/api/test/withdraw/preview',
			'tokenField' => 'withdrawToken',
			'fields' => ['reason', 'withdrawToken'],
			'label' => 'Withdraw from contract here',
		],
		[
			'id' => 'pay',
			'guest' => true,
			'endpoint' => '/apps/portaliq/api/test/pay',
			'tokenField' => 'payToken',
		],
	];

	/**
	 * A hand-crafted post carrying token A and the token field set to B: the
	 * receiver gets A (REQ-GST-002, scenario "The token cannot be swapped").
	 *
	 * @return void
	 */
	public function testTokenIsStampedOverClientValue(): void {
		$calls = [];
		$controller = $this->controller(['token' => 'A', 'withdrawToken' => 'B', 'reason' => 'changed my mind', 'subjectRef' => 'smuggled'], $calls);

		$answer = $controller->act('portaliq', 'withdraw');

		$this->assertSame(200, $answer->getStatus());
		$this->assertCount(1, $calls);
		$this->assertSame('https://cloud.example/apps/portaliq/api/test/withdraw', $calls[0]['url']);
		$this->assertSame(['reason' => 'changed my mind', 'withdrawToken' => 'A'], json_decode($calls[0]['options']['body'], true));
	}//end testTokenIsStampedOverClientValue()

	/**
	 * An unknown app, an unknown action, a non-guest action and an action
	 * without a preview answer the same 404, and nothing is forwarded
	 * (REQ-GST-003).
	 *
	 * @return void
	 */
	/**
	 * A guest action that names a required field refuses an empty one before
	 * the audit and the forward (site-multi-step-forms REQ-SMF-024). The
	 * action goes through the real registry and normaliser.
	 *
	 * @return void
	 */
	public function testAnEmptyRequiredFieldIsRefusedBeforeTheForward(): void {
		$actions = self::ACTIONS;
		$actions[0]['requiredFields'] = ['reason'];
		$calls   = [];
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');

		$answer = $this->controller(['token' => 'A', 'reason' => ''], $calls, $auditor, '{"ok":true}', $actions)->act('portaliq', 'withdraw');

		$this->assertSame(400, $answer->getStatus());
		$this->assertSame(['error' => 'required_missing', 'errors' => ['reason' => '']], $answer->getData());
		$this->assertSame([], $calls, 'nothing is forwarded');

		$calls  = [];
		$filled = $this->controller(['token' => 'A', 'reason' => 'changed my mind'], $calls, null, '{"ok":true}', $actions)->act('portaliq', 'withdraw');
		$this->assertSame(200, $filled->getStatus());
		$this->assertCount(1, $calls);
	}//end testAnEmptyRequiredFieldIsRefusedBeforeTheForward()

	public function testUnknownActionIs404AndNotForwarded(): void {
		$calls = [];
		$controller = $this->controller(['token' => 'A'], $calls);

		$answers = [
			$controller->act('portaliq', 'refund'),
			$controller->act('shillinq', 'withdraw'),
			$controller->preview('portaliq', 'refund'),
			$controller->preview('portaliq', 'pay'),
		];

		foreach ($answers as $answer) {
			$this->assertSame(404, $answer->getStatus());
			$this->assertSame(['error' => 'not_found'], $answer->getData());
		}

		$this->assertSame([], $calls);
	}//end testUnknownActionIs404AndNotForwarded()

	/**
	 * The forward carries the frozen nine claims with audience `guest`, trust
	 * `low`, the serving portal's organisation and a hashed subject; the
	 * preview goes to the declared preview endpoint with the token only, and
	 * no session row is written.
	 *
	 * @return void
	 */
	public function testAssertionCarriesAudienceGuestAndTheNineClaims(): void {
		$calls = [];
		$controller = $this->controller(['token' => 'A', 'reason' => 'x'], $calls);

		$preview = $controller->preview('portaliq', 'withdraw');
		$this->assertSame(200, $preview->getStatus());
		$this->assertSame(
			['preview' => ['ok' => true], 'action' => ['fields' => ['reason'], 'label' => 'Withdraw from contract here']],
			$preview->getData()
		);
		$this->assertSame('https://cloud.example/apps/portaliq/api/test/withdraw/preview', $calls[0]['url']);
		$this->assertSame(['withdrawToken' => 'A'], json_decode($calls[0]['options']['body'], true));

		$parts = explode('.', $calls[0]['options']['headers']['X-Portal-Subject']);
		$this->assertCount(3, $parts);
		$claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
		$this->assertSame(['sub', 'audience', 'organisation', 'trust', 'jti', 'use', 'iat', 'exp', 'iss'], array_keys($claims));
		$this->assertSame('guest', $claims['audience']);
		$this->assertSame('low', $claims['trust']);
		$this->assertSame('org-knip', $claims['organisation']);
		$this->assertSame('guest:' . hash('sha256', 'A'), $claims['sub']);
		$this->assertNotSame('', $claims['jti']);
	}//end testAssertionCarriesAudienceGuestAndTheNineClaims()

	/**
	 * The audit keeps the token's hash as the subject, never the token.
	 *
	 * @return void
	 */
	public function testAuditKeepsOnlyTheTokenHash(): void {
		$calls = [];
		$recorded = [];
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->method('record')->willReturnCallback(
			function (...$args) use (&$recorded): void {
				$recorded[] = $args;
			}
		);
		$controller = $this->controller(['token' => 'secret-token-123'], $calls, $auditor);

		$controller->act('portaliq', 'pay');

		$this->assertCount(1, $recorded);
		$this->assertSame('forward', $recorded[0][0]);
		$this->assertSame('guest:' . hash('sha256', 'secret-token-123'), $recorded[0][1]);
		$this->assertSame('portaliq', $recorded[0][3]);
		$this->assertSame('pay', $recorded[0][4]);
		$this->assertStringNotContainsString('secret-token-123', json_encode($recorded));
	}//end testAuditKeepsOnlyTheTokenHash()

	/**
	 * A missing token is refused before anything is looked up or forwarded,
	 * and only an `https` redirect in the answer reaches the page.
	 *
	 * @return void
	 */
	public function testMissingTokenIsRefusedAndOnlyAnHttpsRedirectIsRelayed(): void {
		$calls = [];
		$this->assertSame(400, $this->controller([], $calls)->act('portaliq', 'pay')->getStatus());
		$this->assertSame([], $calls);

		$answer = $this->controller(['token' => 'A'], $calls, null, '{"redirectUrl":"javascript:alert(1)","message":"ok"}')->act('portaliq', 'pay');
		$this->assertSame(['message' => 'ok'], $answer->getData());

		$answer = $this->controller(['token' => 'A'], $calls, null, '{"redirectUrl":"https://pay.example/checkout/1"}')->act('portaliq', 'pay');
		$this->assertSame(['redirectUrl' => 'https://pay.example/checkout/1'], $answer->getData());
	}//end testMissingTokenIsRefusedAndOnlyAnHttpsRedirectIsRelayed()

	/**
	 * Build the controller on the real registry, forwarder and session service.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<int, array<string, mixed>> $calls Receives each outbound request.
	 * @param AuditTrailService|null $auditor The audit double.
	 * @param string $answerBody What the receiving app answers.
	 *
	 * @return GuestActionController
	 */
	private function controller(array $params, array &$calls, ?AuditTrailService $auditor = null, string $answerBody = '{"ok":true}', array $actions = self::ACTIONS): GuestActionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));

		$provider = new class($actions) {
			public function __construct(private array $actions) {
			}

			public function getAudiences(): array {
				return ['citizen', 'guest'];
			}

			public function getContribution(array $subject): array {
				if (($subject['audience'] ?? '') !== 'guest') {
					return ['actions' => []];
				}

				return ['actions' => $this->actions];
			}
		};
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['portaliq']);
		$apps->method('getAppInfo')->willReturn(['id' => 'portaliq']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($provider) {
				if ($id === self::PROVIDER_FQCN) {
					return $provider;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new GuestActionRegistry($apps, new PortalProviderLocator($apps, $container, $logger), $logger);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = '') => ($key === 'jwt_signing_secret' ? str_repeat('s', 48) : $default)
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method($this->anything());
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($key === 'jwt_signing_secret' ? str_repeat('s', 48) : $default)
		);
		$session = new PortalSessionService(
			$config,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$writer,
			$this->createMock(PortalObjectReader::class),
			$this->createMock(AuditTrailService::class),
			$appConfig
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($answerBody);
		$response->method('getStatusCode')->willReturn(200);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$calls, $response) {
				$calls[] = ['url' => $url, 'options' => $options];
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path) => 'https://cloud.example' . $path);

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'knip', 'organisation' => 'org-knip']);

		return new GuestActionController(
			$request,
			$registry,
			new PortalActionForwarder($request, new InstanceLoopback($clientService, $urls, $this->createMock(InternalBaseUrl::class), $this->createMock(LoggerInterface::class)), $session),
			$portals,
			($auditor ?? $this->createMock(AuditTrailService::class))
		);
	}//end controller()
}//end class
