<?php

/**
 * Unit tests for SignInAddressChange: a stolen link cannot move the address.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#9
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\Identity\EmailLink\SignInAddressChange;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * An `email-link` session (`low`) is refused; staff and `substantial` change
 * it, void the unspent links and tell the old address.
 */
class SignInAddressChangeTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * Old addresses told.
	 *
	 * @var array<int, string>
	 */
	private array $told = [];

	/**
	 * @return void
	 */
	public function testAnEmailLinkSessionCannotChangeTheSignInAddress(): void {
		$id = $this->seedRow('portalAccount', $this->account());

		$outcome = $this->change()->change(account: $this->rows[$id], newAddress: 'thief@example.nl', byStaff: false, trust: 'low');

		$this->assertSame(SignInAddressChange::TRUST_TOO_LOW, $outcome);
		$this->assertSame('tom@example.nl', $this->rows[$id]['signInAddress']);
		$this->assertSame([], $this->told);
	}//end testAnEmailLinkSessionCannotChangeTheSignInAddress()

	/**
	 * @return void
	 */
	public function testStaffChangeItVoidTheLinksAndTellTheOldAddress(): void {
		$id      = $this->seedRow('portalAccount', $this->account());
		$service = $this->change();
		$this->tokens()->issue(account: $this->rows[$id], portal: 'academie', cookieHash: 'c', addressHash: 'h');

		$outcome = $service->change(account: $this->rows[$id], newAddress: ' Tom.New@Example.nl ', byStaff: true);

		$this->assertSame(SignInAddressChange::CHANGED, $outcome);
		$this->assertSame('tom.new@example.nl', $this->rows[$id]['signInAddress']);
		$this->assertSame(['tom@example.nl'], $this->told);
		$this->assertSame('void', $this->storedRows('portalEmailLink')[0]['state']);
	}//end testStaffChangeItVoidTheLinksAndTellTheOldAddress()

	/**
	 * @return void
	 */
	public function testASubstantialSessionMayChangeItAndABadAddressIsRefused(): void {
		$id = $this->seedRow('portalAccount', $this->account());

		$this->assertSame(SignInAddressChange::REFUSED, $this->change()->change(account: $this->rows[$id], newAddress: 'not-an-address', byStaff: false, trust: 'substantial'));
		$this->assertSame(SignInAddressChange::CHANGED, $this->change()->change(account: $this->rows[$id], newAddress: 'tom2@example.nl', byStaff: false, trust: 'substantial'));
	}//end testASubstantialSessionMayChangeItAndABadAddressIsRefused()

	/**
	 * @return array<string, mixed>
	 */
	private function account(): array {
		return ['subjectRef' => 'email:tom', 'organisation' => 'academie', 'identityType' => 'email', 'status' => 'active', 'signInAddress' => 'tom@example.nl'];
	}//end account()

	/**
	 * @return EmailLinkTokens
	 */
	private function tokens(): EmailLinkTokens {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('q2', 24));

		return new EmailLinkTokens($this->fakeReader(), $this->fakeWriter(), $random, new ClaimLock($this->createMock(ILockingProvider::class), 0));
	}//end tokens()

	/**
	 * @return SignInAddressChange
	 */
	private function change(): SignInAddressChange {
		$mailer = $this->createMock(PortalIdentityMailer::class);
		$mailer->method('sendSignInAddressChanged')->willReturnCallback(
			function (string $email): bool {
				$this->told[] = $email;
				return true;
			}
		);

		return new SignInAddressChange($this->fakeWriter(), new EmailLinkAddress(), $this->tokens(), $mailer);
	}//end change()
}//end class
