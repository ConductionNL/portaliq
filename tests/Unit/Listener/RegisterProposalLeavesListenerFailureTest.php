<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\Portaliq\Listener\RegisterProposalLeavesListener;
use OCA\Portaliq\Service\Proposals\ChangeProposalsProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A leaf registration that fails is logged and never takes the catalogue down.
 *
 * @covers \OCA\Portaliq\Listener\RegisterProposalLeavesListener
 */
class RegisterProposalLeavesListenerFailureTest extends TestCase {

	/**
	 * Skip without OpenRegister's leaf event.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(RegisterLeafProvidersEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not loadable: run inside Nextcloud or set PORTALIQ_OPENREGISTER_LIB.');
		}
	}//end setUp()

	/**
	 * A refused registration is logged as a warning with its message and exception.
	 *
	 * @return void
	 */
	public function testARefusedRegistrationIsLoggedAndSwallowed(): void {
		$failure = new RuntimeException('duplicate leaf id');
		$event = $this->getMockBuilder(RegisterLeafProvidersEvent::class)->onlyMethods(['registerLeaf'])->getMock();
		$event->expects($this->once())->method('registerLeaf')->willThrowException($failure);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq could not register its change-proposal leaves: duplicate leaf id',
			['exception' => $failure]
		);

		$provider = $this->getMockBuilder(ChangeProposalsProvider::class)->disableOriginalConstructor()->getMock();
		(new RegisterProposalLeavesListener($provider, $l10n, $logger))->handle($event);
	}//end testARefusedRegistrationIsLoggedAndSwallowed()
}//end class
