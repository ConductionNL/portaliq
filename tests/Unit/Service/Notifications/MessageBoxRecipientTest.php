<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\Notifications\MessageBoxRecipient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The subject a message-box notice is addressed to: the contributing app's own
 * provider answers it, and every uncertainty — no provider, an uncallable
 * method, a throw, a blank answer — fails closed to no recipient.
 */
#[CoversClass(MessageBoxRecipient::class)]
class MessageBoxRecipientTest extends TestCase {
	/**
	 * A provider with the named method answers the recipient, trimmed.
	 *
	 * @return void
	 */
	public function testTheProvidersMethodAnswersTheRecipient(): void {
		$provider = new class {
			public function recipientFor(string $id): string {
				return '  sub-for-' . $id . ' ';
			}
		};

		$recipient = $this->recipient(provider: $provider)->forRecord(record: ['app' => 'dossiq', 'id' => 'rec-7'], method: 'recipientFor');

		$this->assertSame('sub-for-rec-7', $recipient);
	}//end testTheProvidersMethodAnswersTheRecipient()

	/**
	 * No provider for the app is no recipient.
	 *
	 * @return void
	 */
	public function testNoProviderIsNoRecipient(): void {
		$this->assertNull($this->recipient(provider: null)->forRecord(record: ['app' => 'x', 'id' => 'r'], method: 'recipientFor'));
	}//end testNoProviderIsNoRecipient()

	/**
	 * A method the provider does not expose callably is no recipient and is
	 * never invoked.
	 *
	 * @return void
	 */
	public function testAnUncallableMethodIsNoRecipient(): void {
		$provider = new class {
			public function recipientFor(string $id): string {
				return 'sub';
			}
		};

		// 'missing' is accepted by name but not a method on the provider.
		$this->assertNull($this->recipient(provider: $provider)->forRecord(record: ['app' => 'x', 'id' => 'r'], method: 'missing'));
		// A reserved name is refused outright.
		$this->assertNull($this->recipient(provider: $provider)->forRecord(record: ['app' => 'x', 'id' => 'r'], method: 'getAudience'));
	}//end testAnUncallableMethodIsNoRecipient()

	/**
	 * A throwing provider method is logged and yields no recipient.
	 *
	 * @return void
	 */
	public function testAThrowingMethodIsLoggedAndNoRecipient(): void {
		$provider = new class {
			public function recipientFor(string $id): string {
				throw new RuntimeException('boom');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertNull($this->recipient(provider: $provider, logger: $logger)->forRecord(record: ['app' => 'x', 'id' => 'r'], method: 'recipientFor'));
	}//end testAThrowingMethodIsLoggedAndNoRecipient()

	/**
	 * A blank or non-string answer is no recipient.
	 *
	 * @return void
	 */
	public function testABlankAnswerIsNoRecipient(): void {
		$blank = new class {
			public function recipientFor(string $id): string {
				return '   ';
			}
		};
		$nonString = new class {
			public function recipientFor(string $id): ?string {
				return null;
			}
		};

		$this->assertNull($this->recipient(provider: $blank)->forRecord(record: ['app' => 'x', 'id' => 'r'], method: 'recipientFor'));
		$this->assertNull($this->recipient(provider: $nonString)->forRecord(record: ['app' => 'x', 'id' => 'r'], method: 'recipientFor'));
	}//end testABlankAnswerIsNoRecipient()

	/**
	 * A recipient service whose locator answers the given provider.
	 *
	 * @param object|null          $provider The provider the locator returns.
	 * @param LoggerInterface|null $logger   The logger, or a dummy.
	 *
	 * @return MessageBoxRecipient
	 */
	private function recipient(?object $provider, ?LoggerInterface $logger = null): MessageBoxRecipient {
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($provider);

		return new MessageBoxRecipient($locator, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end recipient()
}//end class
