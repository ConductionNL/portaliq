<?php

/**
 * Fail-closed paths of the e-mail-link flow: when a dependency gives back
 * nothing, no link is issued, no session is granted and no address is moved.
 * These branches return the refusing answer rather than throwing, and nothing
 * else here asserts them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-token-is-strong-stored-as-a-hash-and-spent-once-req-iwi-009
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkEligibility;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkLimits;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\Identity\EmailLink\SignInAddressChange;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens
 * @covers \OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender
 * @covers \OCA\Portaliq\Service\Identity\EmailLink\SignInAddressChange
 *
 * @uses \OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress
 * @uses \OCA\Portaliq\Service\Identity\ClaimLock
 * @uses \OCA\Portaliq\Service\PortalSlugLookup
 */
class EmailLinkFailClosedTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * A generator that hands out the given token.
	 *
	 * @param string $token The token to return from generate().
	 *
	 * @return ISecureRandom
	 */
	private function randomReturning(string $token): ISecureRandom {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn($token);

		return $random;
	}//end randomReturning()

	/**
	 * EmailLinkTokens over the fake store, with the given generator.
	 *
	 * @param ISecureRandom $random The secret source.
	 * @param PortalObjectWriter|null $writer The writer, or the fake store's.
	 *
	 * @return EmailLinkTokens
	 */
	private function tokens(ISecureRandom $random, ?PortalObjectWriter $writer = null): EmailLinkTokens {
		return new EmailLinkTokens(
			$this->fakeReader(),
			$writer ?? $this->fakeWriter(),
			$random,
			new ClaimLock($this->createMock(ILockingProvider::class), 0)
		);
	}//end tokens()

	/**
	 * @return array<string, mixed>
	 */
	private function account(): array {
		return ['subjectRef' => 'email:tom', 'organisation' => 'academie'];
	}//end account()

	public function testIssueRefusesAnAccountMissingItsSubjectRefOrOrganisationOrAddress(): void {
		$tokens = $this->tokens($this->randomReturning(str_repeat('a', EmailLinkTokens::TOKEN_LENGTH)));

		$this->assertNull($tokens->issue(account: ['organisation' => 'academie'], portal: 'p', cookieHash: 'c', addressHash: 'h'));
		$this->assertNull($tokens->issue(account: ['subjectRef' => 'email:tom'], portal: 'p', cookieHash: 'c', addressHash: 'h'));
		// A missing address hash is refused too.
		$this->assertNull($tokens->issue(account: $this->account(), portal: 'p', cookieHash: 'c', addressHash: ''));
		// Nothing was written.
		$this->assertSame([], $this->storedRows('portalEmailLink'));

	}//end testIssueRefusesAnAccountMissingItsSubjectRefOrOrganisationOrAddress()

	public function testIssueRefusesWhenTheGeneratedTokenIsTooShort(): void {
		$tokens = $this->tokens($this->randomReturning('short'));

		$this->assertNull($tokens->issue(account: $this->account(), portal: 'p', cookieHash: 'c', addressHash: 'h'));
		$this->assertSame([], $this->storedRows('portalEmailLink'));

	}//end testIssueRefusesWhenTheGeneratedTokenIsTooShort()

	public function testIssueReturnsNullWhenTheRowCouldNotBeStored(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturn(null);
		$tokens = $this->tokens($this->randomReturning(str_repeat('a', EmailLinkTokens::TOKEN_LENGTH)), $writer);

		$this->assertNull($tokens->issue(account: $this->account(), portal: 'p', cookieHash: 'c', addressHash: 'h'));

	}//end testIssueReturnsNullWhenTheRowCouldNotBeStored()

	public function testSpendRefusesAnEmptyTokenAsNotValid(): void {
		$tokens = $this->tokens($this->randomReturning(str_repeat('a', EmailLinkTokens::TOKEN_LENGTH)));

		$this->assertSame(
			['outcome' => EmailLinkTokens::NOT_VALID, 'row' => null],
			$tokens->spend(token: '', cookieHash: 'c', addressHash: 'h')
		);

	}//end testSpendRefusesAnEmptyTokenAsNotValid()

	public function testTheSenderReportsFailureWhenNoLinkIsIssued(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$setting = $this->createMock(EmailLinkSetting::class);
		$setting->method('offeredBy')->willReturn(true);

		$eligibility = $this->createMock(EmailLinkEligibility::class);
		$eligibility->method('accountFor')->willReturn($this->account());

		$limits = $this->createMock(EmailLinkLimits::class);
		$limits->method('countPortalMail')->willReturn(true);

		// The issue step gives back nothing, so the sender must not claim a send.
		$tokens = $this->createMock(EmailLinkTokens::class);
		$tokens->method('issue')->willReturn(null);

		$mailer = $this->createMock(PortalIdentityMailer::class);
		$reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturn([['organisation' => 'academie', 'slug' => 'academie']]);

		$sender = new EmailLinkSender(
			$reader,
			$setting,
			$eligibility,
			$tokens,
			$limits,
			new EmailLinkAddress(),
			$mailer,
			$logger
		);

		$this->assertFalse($sender->handle(portalSlug: 'academie', address: 'tom@example.nl', cookieHash: 'c'));

	}//end testTheSenderReportsFailureWhenNoLinkIsIssued()

	public function testAChangeReportsFailureWhenTheRowCouldNotBeWritten(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		// The row is not in the store, so the real writer and this double both
		// answer null.
		$writer->method('updateObject')->willReturn(null);
		$change = new SignInAddressChange(
			$writer,
			new EmailLinkAddress(),
			$this->tokens($this->randomReturning(str_repeat('a', EmailLinkTokens::TOKEN_LENGTH))),
			$this->createMock(PortalIdentityMailer::class)
		);

		$outcome = $change->change(
			account: ['uuid' => 'acc-1', 'identityType' => EmailLinkEligibility::IDENTITY_TYPE, 'subjectRef' => 'email:tom', 'organisation' => 'academie'],
			newAddress: 'new@example.nl',
			byStaff: true
		);

		$this->assertSame(SignInAddressChange::FAILED, $outcome);

	}//end testAChangeReportsFailureWhenTheRowCouldNotBeWritten()
}//end class
