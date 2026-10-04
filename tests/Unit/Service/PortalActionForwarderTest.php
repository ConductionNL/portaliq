<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

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
