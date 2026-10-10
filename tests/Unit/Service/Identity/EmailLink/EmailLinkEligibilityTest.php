<?php

/**
 * Unit tests for EmailLinkEligibility: which account an e-mail link may reach.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkEligibility;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * Refusals first: every account that is not exactly one active `email`
 * account of the portal's organisation gets no link.
 */
class EmailLinkEligibilityTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * @return void
	 */
	public function testADigidAccountWithTheAddressGetsNoLink(): void {
		$this->seedRow('portalAccount', $this->account(['identityType' => 'digid', 'identityRef' => 'bsn-hash', 'signInAddress' => 'anna@example.nl']));

		$this->assertNull($this->eligibility()->accountFor(address: 'anna@example.nl', organisation: 'academie'));
	}//end testADigidAccountWithTheAddressGetsNoLink()

	/**
	 * @return void
	 */
	public function testAnEmailAccountHoldingABrokerIdentityGetsNoLink(): void {
		$this->seedRow('portalAccount', $this->account(['identityRef' => 'eh-123']));
		$this->assertNull($this->eligibility()->accountFor(address: 'tom@example.nl', organisation: 'academie'));

		$this->rows = [];
		$this->seedRow('portalAccount', $this->account(['claims' => ['digid' => ['bsn' => 'x']]]));
		$this->assertNull($this->eligibility()->accountFor(address: 'tom@example.nl', organisation: 'academie'));
	}//end testAnEmailAccountHoldingABrokerIdentityGetsNoLink()

	/**
	 * @return void
	 */
	public function testAWithdrawnAccountGetsNoLink(): void {
		$this->seedRow('portalAccount', $this->account(['status' => 'removed']));

		$this->assertNull($this->eligibility()->accountFor(address: 'tom@example.nl', organisation: 'academie'));
	}//end testAWithdrawnAccountGetsNoLink()

	/**
	 * @return void
	 */
	public function testAnAccountOfAnotherOrganisationGetsNoLink(): void {
		$this->readerIgnoresOrganisation = true;
		$this->seedRow('portalAccount', $this->account(['organisation' => 'gemeente-x']));

		$this->assertNull($this->eligibility()->accountFor(address: 'tom@example.nl', organisation: 'academie'));
	}//end testAnAccountOfAnotherOrganisationGetsNoLink()

	/**
	 * @return void
	 */
	public function testAnAddressSharedByTwoAccountsGetsNoLink(): void {
		$this->seedRow('portalAccount', $this->account(['subjectRef' => 'email:a']));
		$this->seedRow('portalAccount', $this->account(['subjectRef' => 'email:b']));

		$this->assertNull($this->eligibility()->accountFor(address: 'tom@example.nl', organisation: 'academie'));
	}//end testAnAddressSharedByTwoAccountsGetsNoLink()

	/**
	 * @return void
	 */
	public function testAnUnknownAddressGetsNoLink(): void {
		$this->seedRow('portalAccount', $this->account());

		$this->assertNull($this->eligibility()->accountFor(address: 'someone@example.nl', organisation: 'academie'));
		$this->assertNull($this->eligibility()->accountFor(address: '', organisation: 'academie'));
	}//end testAnUnknownAddressGetsNoLink()

	/**
	 * An app's own claim (the academy's participant) does not block; the
	 * address is matched whatever its case and spaces.
	 *
	 * @return void
	 */
	public function testOneActiveEmailAccountIsFoundWhateverTheCase(): void {
		$this->seedRow('portalAccount', $this->account(['claims' => ['learniq' => ['participantId' => 'p-1']]]));

		$found = $this->eligibility()->accountFor(address: '  Tom@Example.NL ', organisation: 'academie');

		$this->assertSame('email:tom', $found['subjectRef'] ?? null);
	}//end testOneActiveEmailAccountIsFoundWhateverTheCase()

	/**
	 * A stolen link does not become a lasting account: self-service changes
	 * the contact address (`email`, `contactAddresses`), never the sign-in
	 * address, so links still go only to Tom's address.
	 *
	 * @return void
	 */
	public function testAChangedContactAddressDoesNotMoveTheSignInAddress(): void {
		$id = $this->seedRow('portalAccount', $this->account());
		$this->rows[$id]['email']            = 'thief@example.nl';
		$this->rows[$id]['contactAddresses'] = [['kind' => 'email', 'value' => 'thief@example.nl', 'preferred' => true]];

		$this->assertNull($this->eligibility()->accountFor(address: 'thief@example.nl', organisation: 'academie'));
		$this->assertNotNull($this->eligibility()->accountFor(address: 'tom@example.nl', organisation: 'academie'));
	}//end testAChangedContactAddressDoesNotMoveTheSignInAddress()

	/**
	 * @return void
	 */
	public function testRedeemChecksTheAccountAgain(): void {
		$id = $this->seedRow('portalAccount', $this->account());
		$this->assertNotNull($this->eligibility()->stillEligible(subjectRef: 'email:tom', organisation: 'academie'));

		$this->rows[$id]['status'] = 'suspended';
		$this->assertNull($this->eligibility()->stillEligible(subjectRef: 'email:tom', organisation: 'academie'));
	}//end testRedeemChecksTheAccountAgain()

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function account(array $overrides = []): array {
		return array_merge(
			[
				'subjectRef'    => 'email:tom',
				'audience'      => 'client',
				'organisation'  => 'academie',
				'identityType'  => 'email',
				'status'        => 'active',
				'signInAddress' => 'tom@example.nl',
				'email'         => 'tom@example.nl',
			],
			$overrides
		);
	}//end account()

	/**
	 * @return EmailLinkEligibility
	 */
	private function eligibility(): EmailLinkEligibility {
		return new EmailLinkEligibility($this->fakeReader(), new EmailLinkAddress());
	}//end eligibility()
}//end class
