<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The endpoint rule every forward shares (contribution-pay-screen): an
 * instance-local path and an allowed method, nothing else.
 *
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 */
class PortalActionForwarderTest extends TestCase {
	/**
	 * The forwarder with inert collaborators.
	 *
	 * @return PortalActionForwarder
	 */
	private function forwarder(): PortalActionForwarder {
		return new PortalActionForwarder(
			$this->createMock(IRequest::class),
			$this->createMock(InstanceLoopback::class),
			$this->createMock(PortalSessionService::class),
		);
	}//end forwarder()

	/**
	 * An instance-local path with an allowed method may be forwarded; a URL,
	 * a protocol-relative path, a missing endpoint or an unknown method may not.
	 *
	 * @return void
	 */
	public function testOnlyAnInstanceLocalEndpointWithAnAllowedMethodIsForwardable(): void {
		$forwarder = $this->forwarder();

		$this->assertTrue($forwarder->isForwardable(action: ['endpoint' => '/apps/shillinq/api/portal/payments/initiate']));
		$this->assertTrue($forwarder->isForwardable(action: ['endpoint' => '/apps/filinq/api/portal/signing/sign', 'method' => 'patch']));
		$this->assertFalse($forwarder->isForwardable(action: ['endpoint' => 'https://evil.example/pay']));
		$this->assertFalse($forwarder->isForwardable(action: ['endpoint' => '//evil.example/pay']));
		$this->assertFalse($forwarder->isForwardable(action: ['endpoint' => '/x?next=https://evil.example']));
		$this->assertFalse($forwarder->isForwardable(action: ['endpoint' => 'apps/shillinq/pay']));
		$this->assertFalse($forwarder->isForwardable(action: []));
		$this->assertFalse($forwarder->isForwardable(action: ['endpoint' => '/apps/shillinq/pay', 'method' => 'TRACE']));
	}//end testOnlyAnInstanceLocalEndpointWithAnAllowedMethodIsForwardable()

	/**
	 * The forward goes through InstanceLoopback with the endpoint PATH, so a
	 * public address the server cannot reach falls back like every other
	 * self-call. The assertion travels; the client's Authorization does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
	 */
	public function testTheForwardGoesThroughTheInstanceLoopback(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer client-token');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueAssertion')->willReturn('minted-assertion');
		$response = $this->createMock(IResponse::class);

		$loopback = $this->getMockBuilder(InstanceLoopback::class)
			->disableOriginalConstructor()
			->onlyMethods(['request'])
			->getMock();
		$loopback->expects($this->once())->method('request')->with(
			'PATCH',
			'/apps/filinq/api/portal/signing/sign',
			$this->callback(
				static fn (array $options): bool => $options['headers']['X-Portal-Subject'] === 'minted-assertion'
					&& isset($options['headers']['Authorization']) === false
					&& $options['body'] === '{"a":1}'
			)
		)->willReturn($response);

		$forwarder = new PortalActionForwarder($request, $loopback, $session);

		$this->assertSame(
			$response,
			$forwarder->forward(action: ['endpoint' => '/apps/filinq/api/portal/signing/sign', 'method' => 'patch'], subject: ['subjectRef' => 's-1'], whitelisted: ['a' => 1])
		);
	}//end testTheForwardGoesThroughTheInstanceLoopback()

	/**
	 * When no address answers, the forward degrades to null as before.
	 *
	 * @return void
	 */
	/**
	 * A row action that declares `files` forwards its uploads multipart beside the fields (REQ-RAF-001).
	 *
	 * @return void
	 */
	public function testFilesGoMultipartBesideTheFields(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'raf');
		file_put_contents($tmp, 'scan bytes');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueAssertion')->willReturn('minted-assertion');
		$seen = [];

		$loopback = $this->getMockBuilder(InstanceLoopback::class)
			->disableOriginalConstructor()
			->onlyMethods(['request'])
			->getMock();
		$loopback->expects($this->once())->method('request')->willReturnCallback(
			function (string $method, string $path, array $options) use (&$seen): IResponse {
				$seen = $options;
				return $this->createMock(IResponse::class);
			}
		);

		$forwarder = new PortalActionForwarder($this->createMock(IRequest::class), $loopback, $session);
		$forwarder->forward(
			action: ['endpoint' => '/apps/dossiq/api/portal/woo/answer', 'files' => ['field' => 'attachments', 'max' => 5, 'maxBytes' => 1000]],
			subject: ['subjectRef' => 's-1'],
			whitelisted: ['answer' => 'Zie bijlage', 'requestId' => 'r-1', 'extra' => ['a' => 1]],
			files: [['name' => 'scan.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'size' => 10]]
		);
		unlink($tmp);

		$this->assertArrayNotHasKey('body', $seen);
		$this->assertArrayNotHasKey('Content-Type', $seen['headers']);
		$this->assertSame('minted-assertion', $seen['headers']['X-Portal-Subject']);
		$this->assertSame(['answer', 'requestId', 'extra', 'attachments[]'], array_column($seen['multipart'], 'name'));
		$this->assertSame('{"a":1}', $seen['multipart'][2]['contents']);
		$this->assertSame('scan.pdf', $seen['multipart'][3]['filename']);
		$this->assertSame('application/pdf', $seen['multipart'][3]['headers']['Content-Type']);
		$this->assertIsResource($seen['multipart'][3]['contents']);
	}//end testFilesGoMultipartBesideTheFields()

	/**
	 * Without files, or on an action without `files`, the forward stays JSON.
	 *
	 * @return void
	 */
	public function testWithoutFilesTheForwardStaysJson(): void {
		$seen = [];
		$loopback = $this->getMockBuilder(InstanceLoopback::class)
			->disableOriginalConstructor()
			->onlyMethods(['request'])
			->getMock();
		$loopback->method('request')->willReturnCallback(
			function (string $method, string $path, array $options) use (&$seen): IResponse {
				$seen[] = $options;
				return $this->createMock(IResponse::class);
			}
		);
		$forwarder = new PortalActionForwarder($this->createMock(IRequest::class), $loopback, $this->createMock(PortalSessionService::class));

		$forwarder->forward(action: ['endpoint' => '/apps/x/api/y', 'files' => ['field' => 'attachments']], subject: [], whitelisted: ['a' => 1]);
		$forwarder->forward(action: ['endpoint' => '/apps/x/api/y'], subject: [], whitelisted: ['a' => 1], files: [['name' => 'n', 'type' => 't', 'tmp_name' => '/nonexistent', 'size' => 1]]);

		foreach ($seen as $options) {
			$this->assertSame('{"a":1}', $options['body']);
			$this->assertArrayNotHasKey('multipart', $options);
		}
	}//end testWithoutFilesTheForwardStaysJson()

	public function testAForwardThatReachesNoAddressDegradesToNull(): void {
		$loopback = $this->getMockBuilder(InstanceLoopback::class)
			->disableOriginalConstructor()
			->onlyMethods(['request'])
			->getMock();
		$loopback->method('request')->willThrowException(new RuntimeException('cURL error 7: refused'));

		$forwarder = new PortalActionForwarder($this->createMock(IRequest::class), $loopback, $this->createMock(PortalSessionService::class));

		$this->assertNull($forwarder->forward(action: ['endpoint' => '/apps/x/api/y'], subject: [], whitelisted: []));
	}//end testAForwardThatReachesNoAddressDegradesToNull()

	/**
	 * The body a resident sends must reach the domain endpoint. Nextcloud's
	 * real request declares getContent() protected, so calling it threw
	 * "Call to protected method" and every forwarded action answered 500 (found
	 * by the Woo journey e2e: "Bewaar in mijn dossier" never saved). The raw
	 * body is read from the input stream when getContent() is not callable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function testTheRawBodyIsRelayedWhenTheRequestHidesGetContent(): void {
		$forwarder = new class(
			$this->createMock(IRequest::class),
			$this->createMock(InstanceLoopback::class),
			$this->createMock(PortalSessionService::class),
		) extends PortalActionForwarder {
			protected function rawInput(): string {
				return '{"collectionId":"c-1"}';
			}

			public function body(): string {
				return $this->requestBody();
			}
		};

		$this->assertSame('{"collectionId":"c-1"}', $forwarder->body());
	}//end testTheRawBodyIsRelayedWhenTheRequestHidesGetContent()
}//end class
