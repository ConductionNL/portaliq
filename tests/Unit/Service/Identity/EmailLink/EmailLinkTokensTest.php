<?php

/**
 * Unit tests for EmailLinkTokens: strong, hashed, spent once.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Expiry, single use, two redeems at once, voiding, the browser and address
 * proofs, and what is stored.
 */
class EmailLinkTokensTest extends TestCase {
	use PortalIdentityStoreTrait;

	private const COOKIE = 'cookie-hash';

	/**
	 * The locks held right now, by key.
	 *
	 * @var array<string, bool>
	 */
	private array $held = [];

	/**
	 * Called inside the writer's update, to start a second redeem mid-spend.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $duringSpend = null;

	/**
	 * @return void
	 */
	public function testOnlyTheHashIsStoredWithTheAccountTheCookieAndTheExpiry(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->once())->method('generate')
			->with(48, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn(str_repeat('a1', 24));

		$token = $this->tokens(random: $random)->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: hash('sha256', 'tom@example.nl'), now: new DateTimeImmutable('2026-10-09T09:00:00+00:00'));

		$row = $this->storedRows('portalEmailLink')[0];
		$this->assertSame(hash('sha256', (string)$token), $row['tokenHash']);
		$this->assertNotContains($token, $row);
		$this->assertSame('email:tom', $row['subjectRef']);
		$this->assertSame(self::COOKIE, $row['cookieHash']);
		$this->assertSame('2026-10-09T09:15:00+00:00', $row['expiresAt']);
		$this->assertArrayNotHasKey('email', $row);
	}//end testOnlyTheHashIsStoredWithTheAccountTheCookieAndTheExpiry()

	/**
	 * @return void
	 */
	public function testAnExpiredLinkSignsNobodyIn(): void {
		$tokens = $this->tokens();
		$token  = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h', now: new DateTimeImmutable('2026-10-09T09:00:00+00:00'));

		$spent = $tokens->spend(token: $token, cookieHash: self::COOKIE, addressHash: '', now: new DateTimeImmutable('2026-10-09T09:16:00+00:00'));

		$this->assertSame(EmailLinkTokens::NOT_VALID, $spent['outcome']);
		$this->assertSame('sent', $this->storedRows('portalEmailLink')[0]['state']);
	}//end testAnExpiredLinkSignsNobodyIn()

	/**
	 * @return void
	 */
	public function testALinkWorksOnceAndTheSecondOpenSaysUsed(): void {
		$tokens = $this->tokens();
		$token  = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h');

		$this->assertSame(EmailLinkTokens::SPENT, $tokens->spend(token: $token, cookieHash: self::COOKIE, addressHash: '')['outcome']);
		$this->assertSame(EmailLinkTokens::USED, $tokens->spend(token: $token, cookieHash: self::COOKIE, addressHash: '')['outcome']);
	}//end testALinkWorksOnceAndTheSecondOpenSaysUsed()

	/**
	 * Two opens at the same moment: the second arrives while the first holds
	 * the lock inside its spend, and does not sign in.
	 *
	 * @return void
	 */
	public function testTwoRedeemsAtOnceGiveOneSession(): void {
		$tokens = $this->tokens();
		$token  = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h');

		$second = null;
		$this->duringSpend = function () use ($tokens, $token, &$second): void {
			$this->duringSpend = null;
			$second = $tokens->spend(token: $token, cookieHash: self::COOKIE, addressHash: '');
		};
		$first = $tokens->spend(token: $token, cookieHash: self::COOKIE, addressHash: '');

		$this->assertSame(EmailLinkTokens::SPENT, $first['outcome']);
		$this->assertSame(EmailLinkTokens::BUSY, $second['outcome'] ?? 'the second redeem never ran');
		$this->assertSame(EmailLinkTokens::USED, $tokens->spend(token: $token, cookieHash: self::COOKIE, addressHash: '')['outcome']);
	}//end testTwoRedeemsAtOnceGiveOneSession()

	/**
	 * @return void
	 */
	public function testANewerLinkVoidsTheOlderOne(): void {
		$tokens = $this->tokens();
		$first  = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h');
		$second = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h');

		$this->assertSame(EmailLinkTokens::NOT_VALID, $tokens->spend(token: $first, cookieHash: self::COOKIE, addressHash: '')['outcome']);
		$this->assertSame(EmailLinkTokens::SPENT, $tokens->spend(token: $second, cookieHash: self::COOKIE, addressHash: '')['outcome']);
	}//end testANewerLinkVoidsTheOlderOne()

	/**
	 * A mail scanner without the cookie presses the button without typing
	 * the address: nothing is spent. A wrong address spends nothing either.
	 *
	 * @return void
	 */
	public function testAnotherBrowserMustTypeTheAddressAndAWrongOneSpendsNothing(): void {
		$tokens = $this->tokens();
		$token  = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: hash('sha256', 'tom@example.nl'));

		$this->assertSame(EmailLinkTokens::ADDRESS_NEEDED, $tokens->spend(token: $token, cookieHash: '', addressHash: '')['outcome']);
		$this->assertSame(EmailLinkTokens::ADDRESS_NEEDED, $tokens->spend(token: $token, cookieHash: 'other-browser', addressHash: '')['outcome']);
		$this->assertSame(EmailLinkTokens::ADDRESS_WRONG, $tokens->spend(token: $token, cookieHash: '', addressHash: hash('sha256', 'eve@example.nl'))['outcome']);
		$this->assertSame('sent', $this->storedRows('portalEmailLink')[0]['state']);

		$this->assertSame(EmailLinkTokens::SPENT, $tokens->spend(token: $token, cookieHash: '', addressHash: hash('sha256', 'tom@example.nl'))['outcome']);
	}//end testAnotherBrowserMustTypeTheAddressAndAWrongOneSpendsNothing()

	/**
	 * @return void
	 */
	public function testStaffVoidEveryUnspentLinkOfOneAccountOnly(): void {
		$tokens = $this->tokens();
		$toms   = (string)$tokens->issue(account: $this->account(), portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h');
		$annas  = (string)$tokens->issue(account: ['subjectRef' => 'email:anna', 'organisation' => 'academie'], portal: 'academie', cookieHash: self::COOKIE, addressHash: 'h');

		$this->assertSame(1, $tokens->voidFor(subjectRef: 'email:tom', organisation: 'academie'));
		$this->assertSame(EmailLinkTokens::NOT_VALID, $tokens->peek(token: $toms)['state']);
		$this->assertSame('live', $tokens->peek(token: $annas)['state']);
	}//end testStaffVoidEveryUnspentLinkOfOneAccountOnly()

	/**
	 * @return void
	 */
	public function testAnUnknownTokenIsNotValid(): void {
		$this->assertSame(EmailLinkTokens::NOT_VALID, $this->tokens()->spend(token: 'nope', cookieHash: self::COOKIE, addressHash: '')['outcome']);
		$this->assertSame(EmailLinkTokens::NOT_VALID, $this->tokens()->spend(token: '', cookieHash: self::COOKIE, addressHash: '')['outcome']);
	}//end testAnUnknownTokenIsNotValid()

	/**
	 * @return array<string, string>
	 */
	private function account(): array {
		return ['subjectRef' => 'email:tom', 'organisation' => 'academie'];
	}//end account()

	/**
	 * @param ISecureRandom|null $random The random source.
	 *
	 * @return EmailLinkTokens
	 */
	private function tokens(?ISecureRandom $random = null): EmailLinkTokens {
		if ($random === null) {
			$counter = 0;
			$random  = $this->createMock(ISecureRandom::class);
			$random->method('generate')->willReturnCallback(
				function (int $length) use (&$counter): string {
					$counter++;
					return str_pad((string)$counter, $length, 'x');
				}
			);
		}

		return new EmailLinkTokens($this->fakeReader(), $this->spendingWriter(), $random, new ClaimLock($this->locks(), 0));
	}//end tokens()

	/**
	 * The store's writer, with a hook inside an update.
	 *
	 * @return PortalObjectWriter
	 */
	private function spendingWriter(): PortalObjectWriter {
		$inner  = $this->fakeWriter();
		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject'])
			->getMock();
		$writer->method('createObject')->willReturnCallback(fn (...$args) => $inner->createObject(...$args));
		$writer->method('updateObject')->willReturnCallback(
			function (...$args) use ($inner) {
				$hook = $this->duringSpend;
				if ($hook !== null && (($args['data'] ?? $args[6])['state'] ?? '') === 'used') {
					$hook();
				}

				return $inner->updateObject(...$args);
			}
		);

		return $writer;
	}//end spendingWriter()

	/**
	 * An in-memory exclusive lock.
	 *
	 * @return ILockingProvider
	 */
	private function locks(): ILockingProvider {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			function (string $path): void {
				if (isset($this->held[$path]) === true) {
					throw new LockedException($path);
				}

				$this->held[$path] = true;
			}
		);
		$locks->method('releaseLock')->willReturnCallback(
			function (string $path): void {
				unset($this->held[$path]);
			}
		);

		return $locks;
	}//end locks()
}//end class
