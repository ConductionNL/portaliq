<?php

/**
 * Unit tests for the message box dispatch job around its sender.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\MessageBoxDispatchJob;
use OCA\Portaliq\Service\Notifications\MessageBoxSender;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * A queued argument that is not an array is ignored, and a failing send is
 * logged by exception class only, never by its message.
 */
#[CoversClass(MessageBoxDispatchJob::class)]
class MessageBoxDispatchJobFailureTest extends TestCase {

	/**
	 * A non-array argument sends nothing.
	 *
	 * @return void
	 */
	public function testANonArrayArgumentSendsNothing(): void {
		$sender = $this->createMock(MessageBoxSender::class);
		$sender->expects($this->never())->method('send');
		$job = new MessageBoxDispatchJob(time: $this->createMock(ITimeFactory::class), sender: $sender, logger: $this->createMock(LoggerInterface::class));

		(new ReflectionMethod($job, 'run'))->invoke($job, 'subjectRef=bsn:1');
	}//end testANonArrayArgumentSendsNothing()

	/**
	 * A failing send is logged with the exception class, not its message.
	 *
	 * @return void
	 */
	public function testAFailingSendIsLoggedByClassOnly(): void {
		$argument = ['subjectRef' => 'bsn:1'];
		$sender   = $this->createMock(MessageBoxSender::class);
		$sender->expects($this->once())->method('send')->with($argument)->willThrowException(new RuntimeException('recipient someone@example.org refused'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Portaliq: MessageBoxDispatchJob failed', ['exception' => RuntimeException::class]);
		$job = new MessageBoxDispatchJob(time: $this->createMock(ITimeFactory::class), sender: $sender, logger: $logger);

		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end testAFailingSendIsLoggedByClassOnly()
}//end class
