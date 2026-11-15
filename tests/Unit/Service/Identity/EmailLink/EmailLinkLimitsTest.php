<?php

/**
 * Unit tests for the e-mail link rate limits.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use Exception;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkLimits;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\TestCase;

/**
 * Per address and per portal, counted in the Nextcloud limiter.
 */
class EmailLinkLimitsTest extends TestCase {
	/**
	 * @return void
	 */
	public function testAnAddressUnderItsLimitIsLetThrough(): void {
		$limiter = $this->createMock(ILimiter::class);
		$limiter->expects($this->once())->method('registerAnonRequest')
			->with('portaliq-email-link-address', EmailLinkLimits::PER_ADDRESS, EmailLinkLimits::PERIOD, 'address:abc');

		$this->assertTrue((new EmailLinkLimits($limiter))->countAddress(addressHash: 'abc'));
	}//end testAnAddressUnderItsLimitIsLetThrough()

	/**
	 * @return void
	 */
	public function testAnAddressOverItsLimitIsRefused(): void {
		$limiter = $this->createMock(ILimiter::class);
		$limiter->method('registerAnonRequest')->willThrowException(
			new class('limit') extends Exception implements IRateLimitExceededException {
			}
		);

		$this->assertFalse((new EmailLinkLimits($limiter))->countAddress(addressHash: 'abc'));
	}//end testAnAddressOverItsLimitIsRefused()

	/**
	 * @return void
	 */
	public function testThePortalCountKeysOnAHashOfThePortal(): void {
		$limiter = $this->createMock(ILimiter::class);
		$limiter->expects($this->once())->method('registerAnonRequest')
			->with('portaliq-email-link-portal', EmailLinkLimits::PER_PORTAL, EmailLinkLimits::PERIOD, 'portal:' . hash('sha256', 'academie'));

		$this->assertTrue((new EmailLinkLimits($limiter))->countPortalMail(portal: 'academie'));
	}//end testThePortalCountKeysOnAHashOfThePortal()
}//end class
