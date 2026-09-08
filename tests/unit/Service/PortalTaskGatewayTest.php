<?php

/**
 * PortalTaskGateway URL-building tests (WOO-568).
 *
 * The seam URL MUST come from the route table: a hard-coded
 * `/apps/openregister/api/portal-tasks` put through `getAbsoluteURL()` lost
 * `index.php` on every instance without pretty URLs, so the whole task seam
 * answered the webserver's 404 while `contributions.tasks.enabled` said true.
 * These tests pin the contract on both kinds of instance and the fail-soft
 * posture when the route table does not know the seam.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\PortalTaskGateway
 */
final class PortalTaskGatewayTest extends TestCase {
	private IClient&MockObject $client;

	private IURLGenerator&MockObject $urlGenerator;

	private PortalSessionService&MockObject $session;

	private LoggerInterface&MockObject $logger;

	private PortalTaskGateway $gateway;

	/**
	 * The subject shape the proxy resolves from the bearer.
	 *
	 * @var array<string, mixed>
	 */
	private const SUBJECT = ['subjectRef' => 'dev-supplier', 'audience' => 'supplier', 'organisation' => 'dev-org'];

	protected function setUp(): void {
		parent::setUp();

		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->session = $this->createMock(PortalSessionService::class);
		$this->session->method('issueAssertion')->willReturn('signed-assertion');
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->gateway = new PortalTaskGateway(
			$clientService,
			$this->urlGenerator,
			$this->session,
			$this->createMock(IAppManager::class),
			$this->logger,
		);
	}//end setUp()

	/**
	 * A 200 JSON response double.
	 *
	 * @param string $body The JSON body.
	 *
	 * @return IResponse&MockObject
	 */
	private function jsonResponse(string $body = '{"results":[],"total":0}'): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end jsonResponse()

	/**
	 * Route-table double for an instance WITHOUT pretty URLs: `linkToRoute()`
	 * yields the `/index.php/...` form, `getAbsoluteURL()` prefixes the host.
	 *
	 * @param string $expectedRoute The route name the gateway must ask for.
	 * @param array<string, int|string> $expectedParameters The parameters it must pass.
	 * @param string $path What the route table answers.
	 *
	 * @return void
	 */
	private function routeTableAnswers(string $expectedRoute, array $expectedParameters, string $path): void {
		$this->urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with($expectedRoute, $expectedParameters)
			->willReturn($path);
		$this->urlGenerator->expects($this->once())
			->method('getAbsoluteURL')
			->with($path)
			->willReturn('http://nextcloud.local' . $path);
	}//end routeTableAnswers()

	public function testListTasksBuildsTheIndexUrlFromTheRouteTableWithIndexPhp(): void {
		$this->routeTableAnswers(
			'openregister.portalTask.index',
			['limit' => 25, 'offset' => 0],
			'/index.php/apps/openregister/api/portal-tasks?limit=25&offset=0'
		);
		$this->client->expects($this->once())
			->method('get')
			->with(
				'http://nextcloud.local/index.php/apps/openregister/api/portal-tasks?limit=25&offset=0',
				$this->callback(static fn (array $options): bool => ($options['headers']['X-Portal-Subject'] ?? null) === 'signed-assertion'
					&& ($options['http_errors'] ?? null) === false)
			)
			->willReturn($this->jsonResponse());

		$answer = $this->gateway->listTasks(self::SUBJECT);

		self::assertSame(['status' => 200, 'body' => ['results' => [], 'total' => 0]], $answer);
	}//end testListTasksBuildsTheIndexUrlFromTheRouteTableWithIndexPhp()

	public function testListTasksClampsPagingBeforeItReachesTheRoute(): void {
		$this->routeTableAnswers(
			'openregister.portalTask.index',
			['limit' => 1, 'offset' => 0],
			'/index.php/apps/openregister/api/portal-tasks?limit=1&offset=0'
		);
		$this->client->method('get')->willReturn($this->jsonResponse());

		self::assertNotNull($this->gateway->listTasks(self::SUBJECT, -5, -1));
	}//end testListTasksClampsPagingBeforeItReachesTheRoute()

	public function testGetTaskBuildsTheShowUrlOnAPrettyUrlInstance(): void {
		// Pretty URLs on: the route table answers WITHOUT index.php and the
		// gateway must not add or strip anything — the table is the authority.
		$this->routeTableAnswers(
			'openregister.portalTask.show',
			['uuid' => 'abc-123'],
			'/apps/openregister/api/portal-tasks/abc-123'
		);
		$this->client->expects($this->once())
			->method('get')
			->with('http://nextcloud.local/apps/openregister/api/portal-tasks/abc-123', $this->anything())
			->willReturn($this->jsonResponse('{"uuid":"abc-123"}'));

		$answer = $this->gateway->getTask(self::SUBJECT, 'abc-123');

		self::assertSame(200, $answer['status'] ?? null);
		self::assertSame('abc-123', $answer['body']['uuid'] ?? null);
	}//end testGetTaskBuildsTheShowUrlOnAPrettyUrlInstance()

	public function testCompleteTaskPostsMultipartToTheCompleteRoute(): void {
		$this->routeTableAnswers(
			'openregister.portalTask.complete',
			['uuid' => 'abc-123'],
			'/index.php/apps/openregister/api/portal-tasks/abc-123/complete'
		);
		$this->client->expects($this->once())
			->method('post')
			->with(
				'http://nextcloud.local/index.php/apps/openregister/api/portal-tasks/abc-123/complete',
				$this->callback(static function (array $options): bool {
					$names = array_column($options['multipart'] ?? [], 'name');

					return $names === ['answers', 'comment', 'outcome'];
				})
			)
			->willReturn($this->jsonResponse('{"state":"completed"}'));
		$this->client->expects($this->never())->method('get');

		$answer = $this->gateway->completeTask(self::SUBJECT, 'abc-123', ['a' => 1], 'done', 'submitted');

		self::assertSame('completed', $answer['body']['state'] ?? null);
	}//end testCompleteTaskPostsMultipartToTheCompleteRoute()

	public function testAnUnknownSeamRouteDegradesToNullInsteadOfThrowing(): void {
		$this->urlGenerator->method('linkToRoute')
			->willThrowException(new RuntimeException('Unable to generate a URL for the named route "openregister.portalTask.index"'));
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Cannot resolve the seam route openregister.portalTask.index'));
		$this->client->expects($this->never())->method('get');

		self::assertNull($this->gateway->listTasks(self::SUBJECT));
	}//end testAnUnknownSeamRouteDegradesToNullInsteadOfThrowing()

	public function testAnUnmintableAssertionStillShortCircuitsBeforeAnyUrlIsBuilt(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueAssertion')->willThrowException(new RuntimeException('no secret'));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$gateway = new PortalTaskGateway(
			$clientService,
			$this->urlGenerator,
			$session,
			$this->createMock(IAppManager::class),
			$this->logger,
		);
		$this->urlGenerator->expects($this->never())->method('linkToRoute');

		self::assertNull($gateway->getTask(self::SUBJECT, 'abc-123'));
	}//end testAnUnmintableAssertionStillShortCircuitsBeforeAnyUrlIsBuilt()
}//end class
