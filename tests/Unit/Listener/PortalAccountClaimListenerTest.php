<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Event\PortalAccountClaimRequestedEvent;
use OCA\Portaliq\Listener\PortalAccountClaimListener;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Another app asks to write a claim on a portal account: the claim is
 * written under the asking app, and every way it cannot be is answered
 * `refused`, never left unanswered.
 *
 * @covers \OCA\Portaliq\Listener\PortalAccountClaimListener
 * @uses   \OCA\Portaliq\Event\PortalAccountClaimRequestedEvent
 *
 * @spec openspec/specs/portal-identity-space/spec.md
 */
class PortalAccountClaimListenerTest extends TestCase {

	public function testAWrittenClaimIsAnsweredOk(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->expects($this->once())->method('claim')->with('sanne', 'dossiq', 'iban', 'NL00BANK0123456789')->willReturn(true);
		$event = new PortalAccountClaimRequestedEvent(appId: 'dossiq', subjectRef: 'sanne', claimName: 'iban', value: 'NL00BANK0123456789');

		$this->listener(accounts: $accounts)->handle($event);

		$this->assertSame('ok', $event->getResult());
	}//end testAWrittenClaimIsAnsweredOk()

	public function testAClaimWithoutAnAppIsRefusedUnwritten(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->expects($this->never())->method('claim');
		$event = new PortalAccountClaimRequestedEvent(appId: '', subjectRef: 'sanne', claimName: 'iban', value: 'x');

		$this->listener(accounts: $accounts)->handle($event);

		$this->assertSame('refused', $event->getResult());
	}//end testAClaimWithoutAnAppIsRefusedUnwritten()

	public function testAClaimTheAccountDoesNotTakeIsRefused(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('claim')->willReturn(false);
		$event = new PortalAccountClaimRequestedEvent(appId: 'dossiq', subjectRef: 'nobody', claimName: 'iban', value: 'x');

		$this->listener(accounts: $accounts)->handle($event);

		$this->assertSame('refused', $event->getResult());
	}//end testAClaimTheAccountDoesNotTakeIsRefused()

	public function testAFailingWriteIsRefusedAndLogged(): void {
		$failure = new RuntimeException('register down');
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('claim')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Portal account claim failed', ['exception' => $failure]);
		$event = new PortalAccountClaimRequestedEvent(appId: 'dossiq', subjectRef: 'sanne', claimName: 'iban', value: 'x');

		$this->listener(accounts: $accounts, logger: $logger)->handle($event);

		$this->assertSame('refused', $event->getResult());
	}//end testAFailingWriteIsRefusedAndLogged()

	public function testAnyOtherEventIsLeftAlone(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->expects($this->never())->method('claim');

		$this->listener(accounts: $accounts)->handle(new Event());
	}//end testAnyOtherEventIsLeftAlone()

	private function listener(PortalAccountService $accounts, ?LoggerInterface $logger = null): PortalAccountClaimListener {
		return new PortalAccountClaimListener(accounts: $accounts, logger: $logger ?? $this->createMock(LoggerInterface::class));
	}//end listener()
}//end class
