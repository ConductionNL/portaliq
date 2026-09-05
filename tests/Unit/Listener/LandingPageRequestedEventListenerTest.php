<?php

/**
 * LandingPageRequestedEventListenerTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Listener
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

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Event\LandingPageRequestedEvent;
use OCA\Portaliq\Listener\LandingPageRequestedEventListener;
use OCA\Portaliq\Service\LandingPageProvisioningService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the thin wiring between `LandingPageRequestedEvent` and
 * `LandingPageProvisioningService`, and the platform-fault safety net: an
 * unexpected exception from the service must never propagate out of
 * `dispatchTyped()` into the CALLING app's own request — it is reported as
 * a handled `write_failed` instead.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */
class LandingPageRequestedEventListenerTest extends TestCase {

	/**
	 * @var LandingPageProvisioningService&MockObject
	 */
	private LandingPageProvisioningService $service;

	private LandingPageRequestedEventListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(LandingPageProvisioningService::class);
		$this->listener = new LandingPageRequestedEventListener(
			service: $this->service,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	private function event(): LandingPageRequestedEvent {
		return new LandingPageRequestedEvent(
			sourceApp: 'pipelinq',
			portal: 'open-tilburg',
			route: '/campagne/x',
			title: 'X',
			locale: 'nl',
			article: ['summary' => 's', 'body' => 'b'],
			form: ['fields' => [['id' => 'email', 'label' => 'E-mail', 'type' => 'email']], 'submitLabel' => 'Go']
		);
	}//end event()

	public function testDelegatesToTheProvisioningService(): void {
		$event = $this->event();
		$this->service->expects($this->once())->method('provision')->with($event);

		$this->listener->handle($event);
	}//end testDelegatesToTheProvisioningService()

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->service->expects($this->never())->method('provision');

		$this->listener->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()

	public function testAnUnexpectedServiceExceptionIsReportedAsWriteFailedNeverThrown(): void {
		$this->service->method('provision')->willThrowException(new RuntimeException('boom'));

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertSame('write_failed', $event->getError());
		$this->assertTrue($event->isHandled());
	}//end testAnUnexpectedServiceExceptionIsReportedAsWriteFailedNeverThrown()
}//end class
