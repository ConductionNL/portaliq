<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\ConfirmedAddressJoin;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Joining a waiting account through a confirmed address: every refusal of the
 * holder's own account and session reads nothing, a missing or locked waiting
 * account joins nothing, and a waiting account that changed between the two
 * reads is left alone with its lock released.
 *
 * @covers \OCA\Portaliq\Service\Identity\ConfirmedAddressJoin
 * @uses \OCA\Portaliq\Service\Identity\PortalAccountLookup
 * @uses \OCA\Portaliq\Kernel\Support\SessionTrust
 */
class ConfirmedAddressJoinTest extends TestCase {

	private const EMAIL = 'Jan@Example.nl';

	/**
	 * The holder's account: active, bound to an identity, with the address confirmed.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function account(array $override = []): array {
		return $override + [
			'subjectRef' => 'subject-1',
			'organisation' => 'org-1',
			'status' => 'active',
			'identityRef' => 'digid:123',
			'contactAddresses' => [['kind' => 'email', 'value' => 'jan@example.nl', 'confirmed' => true]],
		];
	}//end account()

	/**
	 * The holder's own session at substantial trust.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function session(array $override = []): array {
		return $override + ['subjectRef' => 'subject-1', 'organisation' => 'org-1', 'trust' => 'substantial'];
	}//end session()

	/**
	 * A pending account waiting for the address.
	 *
	 * @param string $id Its id.
	 *
	 * @return array<string, mixed>
	 */
	private static function waiting(string $id = 'wait-1'): array {
		return ['id' => $id, 'email' => 'jan@example.nl', 'organisation' => 'org-1', 'status' => 'pending', 'verifiedEmail' => true, 'identityRef' => ''];
	}//end waiting()

	/**
	 * The join over a reader answering the given row lists in turn.
	 *
	 * @param list<list<array<string, mixed>>> $reads The rows of each readCollection() call.
	 * @param ClaimLock|null $lock The lock.
	 * @param int|null $expectedReads The number of reads expected, null for any.
	 *
	 * @return ConfirmedAddressJoin
	 */
	private function join(array $reads, ?ClaimLock $lock = null, ?int $expectedReads = null): ConfirmedAddressJoin {
		$reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$expectation = $expectedReads === null ? $this->any() : $this->exactly($expectedReads);
		$reader->expects($expectation)->method('readCollection')->willReturnOnConsecutiveCalls(...($reads === [] ? [[]] : $reads));
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method($this->anything());
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');

		return new ConfirmedAddressJoin($reader, $writer, $auditor, $lock);
	}//end join()

	/**
	 * Every refusal of the account, the session or the address returns false before anything is read.
	 *
	 * @return void
	 */
	public function testEveryRefusalReadsNothing(): void {
		$cases = [
			'no session' => [self::account(), self::EMAIL, null],
			'another subject' => [self::account(), self::EMAIL, self::session(['subjectRef' => 'subject-2'])],
			'another organisation' => [self::account(), self::EMAIL, self::session(['organisation' => 'org-2'])],
			'low trust' => [self::account(), self::EMAIL, self::session(['trust' => 'low'])],
			'no subject' => [self::account(['subjectRef' => '']), self::EMAIL, self::session(['subjectRef' => ''])],
			'no organisation' => [self::account(['organisation' => '']), self::EMAIL, self::session(['organisation' => ''])],
			'not active' => [self::account(['status' => 'pending']), self::EMAIL, self::session()],
			'no identity' => [self::account(['identityRef' => '']), self::EMAIL, self::session()],
			'unconfirmed address' => [self::account(['contactAddresses' => [['kind' => 'email', 'value' => 'jan@example.nl', 'confirmed' => false]]]), self::EMAIL, self::session()],
			'another address' => [self::account(), 'piet@example.nl', self::session()],
			'a phone number' => [self::account(['contactAddresses' => [['kind' => 'phone', 'value' => self::EMAIL, 'confirmed' => true]]]), self::EMAIL, self::session()],
			'no address' => [self::account(), '', self::session()],
		];
		foreach ($cases as $label => [$account, $email, $session]) {
			$this->assertFalse($this->join([], null, 0)->join($account, $email, $session), $label);
		}
	}//end testEveryRefusalReadsNothing()

	/**
	 * With no waiting account for the address there is nothing to join.
	 *
	 * @return void
	 */
	public function testNoWaitingAccountJoinsNothing(): void {
		$lock = $this->createMock(ClaimLock::class);
		$lock->expects($this->never())->method('acquire');

		$this->assertFalse($this->join([[], [], [], []], $lock)->join(self::account(), self::EMAIL, self::session()));
	}//end testNoWaitingAccountJoinsNothing()

	/**
	 * A waiting account another request holds the lock on is left alone and not released.
	 *
	 * @return void
	 */
	public function testALockedWaitingAccountIsLeftAlone(): void {
		$lock = $this->createMock(ClaimLock::class);
		$lock->expects($this->once())->method('acquire')->with('wait-1')->willReturn(false);
		$lock->expects($this->never())->method('release');

		$this->assertFalse($this->join([[self::waiting()]], $lock, 1)->join(self::account(), self::EMAIL, self::session()));
	}//end testALockedWaitingAccountIsLeftAlone()

	/**
	 * A waiting account that is gone or replaced on the second read is not joined, and the lock is released.
	 *
	 * @return void
	 */
	public function testAWaitingAccountThatChangedIsNotJoinedAndTheLockIsReleased(): void {
		foreach (['replaced' => [[self::waiting()], [self::waiting('wait-2')]], 'gone' => [[self::waiting()], [], [], []]] as $label => $reads) {
			$lock = $this->createMock(ClaimLock::class);
			$lock->expects($this->once())->method('acquire')->with('wait-1')->willReturn(true);
			$lock->expects($this->once())->method('release')->with('wait-1');

			$this->assertFalse($this->join($reads, $lock)->join(self::account(), self::EMAIL, self::session()), $label);
		}
	}//end testAWaitingAccountThatChangedIsNotJoinedAndTheLockIsReleased()
}//end class
