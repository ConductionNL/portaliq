<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
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
			$this->createMock(IClientService::class),
			$this->createMock(IURLGenerator::class),
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
}//end class
