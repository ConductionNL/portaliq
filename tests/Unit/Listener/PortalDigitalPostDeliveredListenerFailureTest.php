<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCA\Portaliq\Listener\PortalDigitalPostDeliveredListener;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A status report that cannot be recorded is logged by exception class and
 * never thrown back at integriq.
 *
 * @covers \OCA\Portaliq\Listener\PortalDigitalPostDeliveredListener
 * @uses   \OCA\Portaliq\Service\Notifications\MessageBoxStatus
 */
class PortalDigitalPostDeliveredListenerFailureTest extends TestCase {

	/**
	 * Skip without integriq's event class.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(DigitalPostDeliveredEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}
	}//end setUp()

	/**
	 * A failing read is logged with its class only, and nothing is written.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedByClassAndSwallowed(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readCollection')->willThrowException(new RuntimeException('secret row details'));
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: message box status was not recorded',
			['exception' => RuntimeException::class]
		);

		(new PortalDigitalPostDeliveredListener(reader: $reader, writer: $writer, logger: $logger))->handle(
			new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'delivered', requestedBy: 'portaliq', previousStatus: 'sent')
		);
	}//end testAFailingReadIsLoggedByClassAndSwallowed()

	/**
	 * A report without a message id is not looked up.
	 *
	 * @return void
	 */
	public function testAReportWithoutAMessageIdIsIgnored(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readCollection');
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');

		(new PortalDigitalPostDeliveredListener(reader: $reader, writer: $writer, logger: $this->createMock(LoggerInterface::class)))->handle(
			new DigitalPostDeliveredEvent(messageId: '', status: 'delivered', requestedBy: 'portaliq', previousStatus: 'sent')
		);
	}//end testAReportWithoutAMessageIdIsIgnored()
}//end class
