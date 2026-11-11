<?php

/**
 * PortalAccountProvisionListener: every answer of a provision request, on the
 * pending path and the Nextcloud path.
 *
 * @category Tests
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

use OCA\Portaliq\Event\PortalAccountProvisionRequestedEvent;
use OCA\Portaliq\Listener\PortalAccountProvisionListener;
use OCA\Portaliq\Service\Identity\NextcloudAccountProvisioner;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The provision listener's answers, as recorded.
 */
class PortalAccountProvisionListenerGoldenTest extends TestCase {
	/**
	 * A request for the Nextcloud user `anna`.
	 *
	 * @return PortalAccountProvisionRequestedEvent
	 */
	private static function nextcloudRequest(): PortalAccountProvisionRequestedEvent {
		return new PortalAccountProvisionRequestedEvent(
			appId: 'dossiq',
			audience: 'staff',
			organisation: 'gemeente-x',
			email: 'anna@x.nl',
			displayName: 'Anna',
			nextcloudUid: 'anna',
			portal: 'mijn-x'
		);
	}//end nextcloudRequest()

	/**
	 * The answer slots of an event.
	 *
	 * @param PortalAccountProvisionRequestedEvent $event The event.
	 *
	 * @return array<int, string>
	 */
	private static function slots(PortalAccountProvisionRequestedEvent $event): array {
		return [$event->getSubjectRef(), $event->getStatus(), $event->getRefusal()];
	}//end slots()

	/**
	 * A provision that finds no account to give is refused, and a failure is
	 * logged and unavailable.
	 *
	 * @return void
	 */
	public function testThePendingPathAnswersAsRecorded(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('provision')->willReturn(null);
		$event = new PortalAccountProvisionRequestedEvent(appId: 'dossiq', audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');
		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);
		$this->assertSame(['', '', 'refused'], self::slots($event));

		$failing = $this->createMock(PortalAccountService::class);
		$failing->method('provision')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Portal account provisioning failed', $this->callback(static fn (array $context): bool => $context['exception'] instanceof RuntimeException));
		$event = new PortalAccountProvisionRequestedEvent(appId: 'dossiq', audience: 'client', organisation: 'gemeente-x');
		(new PortalAccountProvisionListener($failing, $logger))->handle($event);
		$this->assertSame(['', '', 'unavailable'], self::slots($event));
	}//end testThePendingPathAnswersAsRecorded()

	/**
	 * The Nextcloud path: no provisioner, a failure, a refusal and an
	 * account, with the request passed on field for field and the pending
	 * path never taken.
	 *
	 * @return void
	 */
	public function testTheNextcloudPathAnswersAsRecorded(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->expects($this->never())->method('provision');

		$event = self::nextcloudRequest();
		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);
		$this->assertSame(['', '', 'unavailable'], self::slots($event));

		$failing = $this->createMock(NextcloudAccountProvisioner::class);
		$failing->method('provision')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Portal account provisioning failed', $this->anything());
		$event = self::nextcloudRequest();
		(new PortalAccountProvisionListener($accounts, $logger, $failing))->handle($event);
		$this->assertSame(['', '', 'unavailable'], self::slots($event));

		$refusing = $this->createMock(NextcloudAccountProvisioner::class);
		$refusing->method('provision')->willReturn('portal_mismatch');
		$event = self::nextcloudRequest();
		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class), $refusing))->handle($event);
		$this->assertSame(['', '', 'portal_mismatch'], self::slots($event));

		$giving = $this->createMock(NextcloudAccountProvisioner::class);
		$giving->expects($this->once())->method('provision')
			->with('dossiq', 'anna', 'mijn-x', 'staff', 'gemeente-x', 'anna@x.nl', 'Anna')
			->willReturn(['subjectRef' => 'nc:anna', 'status' => 'active']);
		$event = self::nextcloudRequest();
		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class), $giving))->handle($event);
		$this->assertSame(['nc:anna', 'active', ''], self::slots($event));
	}//end testTheNextcloudPathAnswersAsRecorded()
}//end class
