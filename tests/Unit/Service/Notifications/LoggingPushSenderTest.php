<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Service\Notifications\LoggingPushSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The interim push transport: it never actually delivers, and it logs without
 * ever putting the body on the record.
 */
#[CoversClass(LoggingPushSender::class)]
class LoggingPushSenderTest extends TestCase {
	/**
	 * The transport does not deliver and reports that it does not.
	 *
	 * @return void
	 */
	public function testItNeitherDeliversNorClaimsTo(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$sender = new LoggingPushSender($logger);

		$this->assertFalse($sender->delivers());
		$this->assertFalse($sender->send(subjectRef: 'sub-1', title: 'Hi', body: 'Body'));
	}//end testItNeitherDeliversNorClaimsTo()

	/**
	 * It logs the send once, with the subject and title, and never the body.
	 *
	 * @return void
	 */
	public function testItLogsWithoutTheBody(): void {
		$context = null;
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->willReturnCallback(
				function (string $message, array $ctx) use (&$context): void {
					$context = $ctx;
				}
			);

		(new LoggingPushSender($logger))->send(subjectRef: 'sub-1', title: 'Hi', body: 'secret body');

		$this->assertSame('sub-1', $context['subjectRef']);
		$this->assertSame('Hi', $context['title']);
		$this->assertArrayNotHasKey('body', $context);
		$this->assertStringNotContainsString('secret body', json_encode($context));
	}//end testItLogsWithoutTheBody()
}//end class
