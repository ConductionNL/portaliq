<?php

/**
 * Unit tests for the queued job that sends a sign-in link.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\EmailLinkRequestJob;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The job hands its three arguments to the sender and nothing else.
 */
class EmailLinkRequestJobTest extends TestCase {
	/**
	 * @return void
	 */
	public function testTheJobPassesPortalAddressAndCookieToTheSender(): void {
		$sender = $this->createMock(EmailLinkSender::class);
		$seen   = [];
		$sender->expects($this->exactly(2))->method('handle')->willReturnCallback(
			function (string $portalSlug, string $address, string $cookieHash) use (&$seen): bool {
				$seen[] = [$portalSlug, $address, $cookieHash];
				return true;
			}
		);
		$job = new EmailLinkRequestJob($this->createMock(ITimeFactory::class), $sender);

		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, ['portal' => 'academie', 'address' => 'tom@example.org', 'cookieHash' => 'ch']);
		$run->invoke($job, null);

		$this->assertSame([['academie', 'tom@example.org', 'ch'], ['', '', '']], $seen);
	}//end testTheJobPassesPortalAddressAndCookieToTheSender()
}//end class
