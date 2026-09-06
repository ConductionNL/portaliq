<?php

/**
 * Tests for the assertion-signed portal task seam client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTaskGateway;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The seam-client contract: every forward carries a freshly minted
 * `X-Portal-Subject` assertion and NEVER the client bearer, refusals are
 * relayed with their status, transport failure and an unmintable assertion
 * both degrade to null (the proxy's 502/unavailable), and availability is
 * openregister + a configured secret, both required.
 *
 * @covers \OCA\Portaliq\Service\PortalTaskGateway
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 */
class PortalTaskGatewayTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 's1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'substantial', 'jti' => 'j1'];

	/**
	 * The assertion is minted server-side per forward, rides in
	 * X-Portal-Subject, and no Authorization header is ever attached.
	 */
	public function testForwardCarriesTheAssertionAndNeverABearer(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use (&$captured) {
				$captured = ['url' => $url, 'options' => $options];

				return $this->response(status: 200, body: '{"results": [], "total": 0, "limit": 25, "offset": 0}');
			}
		);

		$gateway = $this->gateway(client: $client);
		$answer = $gateway->listTasks(subject: self::SUBJECT, limit: 25, offset: 0);

		$this->assertSame(200, $answer['status']);
		$this->assertSame([], $answer['body']['results']);
		$this->assertSame('https://cloud.example/apps/openregister/api/portal-tasks?limit=25&offset=0', $captured['url']);
		$this->assertSame('minted-assertion', $captured['options']['headers']['X-Portal-Subject']);
		$this->assertArrayNotHasKey('Authorization', $captured['options']['headers']);
		// The seam's named refusals must reach the proxy's mapping: relayed,
		// never thrown.
		$this->assertFalse($captured['options']['http_errors']);
	}//end testForwardCarriesTheAssertionAndNeverABearer()

	/**
	 * A named seam refusal is relayed with its status and body untouched.
	 */
	public function testANamedRefusalIsRelayed(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response(status: 404, body: '{"error": "No such task", "code": "no-such-task"}'));

		$answer = $this->gateway(client: $client)->getTask(subject: self::SUBJECT, uuid: 't-1');

		$this->assertSame(404, $answer['status']);
		$this->assertSame('no-such-task', $answer['body']['code']);
	}//end testANamedRefusalIsRelayed()

	/**
	 * Transport failure degrades to null — the proxy answers 502, and the
	 * transport detail never reaches the resident.
	 */
	public function testTransportFailureDegradesToNull(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('connection refused'));

		$this->assertNull($this->gateway(client: $client)->listTasks(subject: self::SUBJECT));
	}//end testTransportFailureDegradesToNull()

	/**
	 * An unmintable assertion (no dedicated signing secret) degrades to null
	 * and performs NO forward at all: unconfigured refuses, it never sends an
	 * unsigned request.
	 */
	public function testAnUnmintableAssertionForwardsNothing(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');
		$client->expects($this->never())->method('post');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueAssertion')->willThrowException(new RuntimeException('no secret'));

		$this->assertNull($this->gateway(client: $client, session: $session)->listTasks(subject: self::SUBJECT));
	}//end testAnUnmintableAssertionForwardsNothing()

	/**
	 * A completion posts multipart: the answers ride as a JSON part, comment
	 * and outcome only when given, and the assertion header is on the POST too.
	 */
	public function testCompletionPostsMultipartWithTheAssertion(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$captured) {
				$captured = ['url' => $url, 'options' => $options];

				return $this->response(status: 200, body: '{"uuid": "t-1", "status": "completed"}');
			}
		);

		$answer = $this->gateway(client: $client)->completeTask(
			subject: self::SUBJECT,
			uuid: 't-1',
			answers: ['field' => 'value'],
			comment: 'klaar',
			outcome: 'submitted'
		);

		$this->assertSame(200, $answer['status']);
		$this->assertSame('https://cloud.example/apps/openregister/api/portal-tasks/t-1/complete', $captured['url']);
		$this->assertSame('minted-assertion', $captured['options']['headers']['X-Portal-Subject']);
		$parts = array_column($captured['options']['multipart'], 'contents', 'name');
		$this->assertSame('{"field":"value"}', $parts['answers']);
		$this->assertSame('klaar', $parts['comment']);
		$this->assertSame('submitted', $parts['outcome']);
	}//end testCompletionPostsMultipartWithTheAssertion()

	/**
	 * A readable upload becomes a `files[]` multipart part carrying its name
	 * and media type; an unreadable tmp path is skipped rather than sent.
	 */
	public function testACompletionAttachesReadableFilesAndSkipsUnreadableOnes(): void {
		$tmp = (string)tempnam(sys_get_temp_dir(), 'ptg');
		file_put_contents($tmp, 'evidence-bytes');

		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$captured) {
				$captured = ['url' => $url, 'options' => $options];

				return $this->response(status: 200, body: '{"uuid": "t-1"}');
			}
		);

		$answer = $this->gateway(client: $client)->completeTask(
			subject: self::SUBJECT,
			uuid: 't-1',
			files: [
				['name' => 'bewijs.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'size' => 14],
				['name' => 'ghost.pdf', 'type' => 'application/pdf', 'tmp_name' => '/nonexistent/ghost', 'size' => 1],
				['name' => 'no-path.pdf'],
			]
		);
		unlink($tmp);

		$this->assertSame(200, $answer['status']);
		$fileParts = array_values(array_filter(
			$captured['options']['multipart'],
			static fn (array $part): bool => $part['name'] === 'files[]'
		));
		$this->assertCount(1, $fileParts);
		$this->assertSame('bewijs.pdf', $fileParts[0]['filename']);
		$this->assertSame('application/pdf', $fileParts[0]['headers']['Content-Type']);
		$this->assertIsResource($fileParts[0]['contents']);
	}//end testACompletionAttachesReadableFilesAndSkipsUnreadableOnes()

	/**
	 * A non-JSON (or empty) seam body degrades to an empty array while the
	 * status is still relayed — the proxy never chokes on a broken body.
	 */
	public function testANonJsonBodyDegradesToAnEmptyArray(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response(status: 500, body: '<html>boom</html>'));

		$answer = $this->gateway(client: $client)->getTask(subject: self::SUBJECT, uuid: 't-1');

		$this->assertSame(500, $answer['status']);
		$this->assertSame([], $answer['body']);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response(status: 204, body: ''));

		$answer = $this->gateway(client: $client)->getTask(subject: self::SUBJECT, uuid: 't-1');
		$this->assertSame(204, $answer['status']);
		$this->assertSame([], $answer['body']);
	}//end testANonJsonBodyDegradesToAnEmptyArray()

	/**
	 * The list page clamps its bounds: a non-positive limit forwards as 1 and
	 * a negative offset as 0, so a hostile query never reaches the seam raw.
	 */
	public function testTheListPageClampsItsBounds(): void {
		$captured = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use (&$captured) {
				$captured['url'] = $url;

				return $this->response(status: 200, body: '{"results": []}');
			}
		);

		$this->gateway(client: $client)->listTasks(subject: self::SUBJECT, limit: 0, offset: -5);

		$this->assertStringEndsWith('portal-tasks?limit=1&offset=0', $captured['url']);
	}//end testTheListPageClampsItsBounds()

	/**
	 * Availability requires BOTH openregister and a configured signing secret.
	 */
	public function testAvailabilityNeedsOpenregisterAndTheSecret(): void {
		$this->assertTrue($this->gateway()->isAvailable());
		$this->assertFalse($this->gateway(openregisterInstalled: false)->isAvailable());

		$session = $this->createMock(PortalSessionService::class);
		$session->method('isConfigured')->willReturn(false);
		$this->assertFalse($this->gateway(session: $session)->isAvailable());
	}//end testAvailabilityNeedsOpenregisterAndTheSecret()

	/**
	 * Build the gateway around a mocked transport.
	 *
	 * @param IClient|null $client The HTTP client mock.
	 * @param PortalSessionService|null $session The session/minter mock.
	 * @param bool $openregisterInstalled Whether openregister reads as installed.
	 */
	private function gateway(
		?IClient $client = null,
		?PortalSessionService $session = null,
		bool $openregisterInstalled = true,
	): PortalTaskGateway {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client ?? $this->createMock(IClient::class));

		if ($session === null) {
			$session = $this->createMock(PortalSessionService::class);
			$session->method('issueAssertion')->willReturn('minted-assertion');
			$session->method('isConfigured')->willReturn(true);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path) => 'https://cloud.example' . $path
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($openregisterInstalled);

		return new PortalTaskGateway(
			$clientService,
			$urlGenerator,
			$session,
			$appManager,
			$this->createMock(LoggerInterface::class)
		);
	}//end gateway()

	/**
	 * A canned IResponse.
	 *
	 * @param int $status The HTTP status.
	 * @param string $body The JSON body.
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()
}//end class
