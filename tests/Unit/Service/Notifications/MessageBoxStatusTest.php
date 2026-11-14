<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Service\Notifications\MessageBoxStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How an integriq delivery status becomes the box status, and the short-lived
 * memory that carries an early status across to the row it belongs to.
 */
#[CoversClass(MessageBoxStatus::class)]
class MessageBoxStatusTest extends TestCase {
	protected function setUp(): void {
		// The early-status store is static, so each test starts from empty.
		$store = new \ReflectionProperty(MessageBoxStatus::class, 'early');
		$store->setAccessible(true);
		$store->setValue(null, []);

	}//end setUp()

	/**
	 * A failed status wins over everything, even a simulated send.
	 *
	 * @return void
	 */
	public function testFailedWinsOverSimulated(): void {
		$status = new MessageBoxStatus();

		$this->assertSame('failed', $status->fromIntegriq(status: 'failed', simulated: true));
		$this->assertSame('failed', $status->fromIntegriq(status: 'failed', simulated: false));
	}//end testFailedWinsOverSimulated()

	/**
	 * A simulated send is 'simulated' unless it failed; the shown statuses pass
	 * through; anything else is 'sent'.
	 *
	 * @return void
	 */
	public function testSimulatedShownAndUnknownStatuses(): void {
		$status = new MessageBoxStatus();

		$this->assertSame('simulated', $status->fromIntegriq(status: 'delivered', simulated: true));
		$this->assertSame('delivered', $status->fromIntegriq(status: 'delivered', simulated: false));
		$this->assertSame('read', $status->fromIntegriq(status: 'read', simulated: false));
		$this->assertSame('sent', $status->fromIntegriq(status: 'queued', simulated: false));
		$this->assertSame('sent', $status->fromIntegriq(status: '', simulated: false));
	}//end testSimulatedShownAndUnknownStatuses()

	/**
	 * A remembered status is taken exactly once; a second take is null, and an
	 * id that was never remembered is null.
	 *
	 * @return void
	 */
	public function testAStatusIsTakenOnlyOnce(): void {
		$status = new MessageBoxStatus();
		$status->remember(messageId: 'm-1', status: 'read');

		$this->assertSame('read', $status->take(messageId: 'm-1'));
		$this->assertNull($status->take(messageId: 'm-1'));
		$this->assertNull($status->take(messageId: 'never'));
	}//end testAStatusIsTakenOnlyOnce()

	/**
	 * The store is bounded: once it reaches 100 it is cleared before the next
	 * is kept, so the earliest ids are forgotten and the newest survives.
	 *
	 * @return void
	 */
	public function testTheStoreIsBoundedAtAHundred(): void {
		$status = new MessageBoxStatus();
		for ($i = 0; $i < 100; $i++) {
			$status->remember(messageId: 'm-' . $i, status: 's-' . $i);
		}

		// The 101st remember trips the clear, so m-0 is gone and only the new one is kept.
		$status->remember(messageId: 'm-new', status: 'fresh');

		$this->assertNull($status->take(messageId: 'm-0'));
		$this->assertNull($status->take(messageId: 'm-99'));
		$this->assertSame('fresh', $status->take(messageId: 'm-new'));
	}//end testTheStoreIsBoundedAtAHundred()
}//end class
