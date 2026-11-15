<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentUser;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Nextcloud account behind the example resident.
 */
#[CoversClass(ExampleResidentUser::class)]
class ExampleResidentUserTest extends TestCase {

	private IUserManager&MockObject $users;
	private ISecureRandom&MockObject $random;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function user(): ExampleResidentUser {
		return new ExampleResidentUser($this->users, $this->random, $this->logger);
	}//end user()

	public function testExistsAsksTheUserManager(): void {
		$this->users->method('userExists')->willReturnMap([['resident', true], ['ghost', false]]);

		$this->assertTrue($this->user()->exists('resident'));
		$this->assertFalse($this->user()->exists('ghost'));
	}//end testExistsAsksTheUserManager()

	public function testANewPasswordIsTwentyFourReadableCharactersPlusAFixedTail(): void {
		$this->random->expects($this->once())->method('generate')
			->with(24, ISecureRandom::CHAR_HUMAN_READABLE)
			->willReturn('abcdefghijkmnpqrstuvwxyz');

		$this->assertSame('abcdefghijkmnpqrstuvwxyz-Zd7!', $this->user()->newPassword());
	}//end testANewPasswordIsTwentyFourReadableCharactersPlusAFixedTail()

	public function testACreatedAccountGetsItsDisplayName(): void {
		$account = $this->createMock(IUser::class);
		$account->expects($this->once())->method('setDisplayName')->with('Sanne de Vries');
		$this->users->expects($this->once())->method('createUser')->with('resident', 'secret')->willReturn($account);

		$this->assertSame('', $this->user()->create('resident', 'Sanne de Vries', 'secret'));
	}//end testACreatedAccountGetsItsDisplayName()

	public function testARefusedAccountSaysSo(): void {
		$this->users->method('createUser')->willReturn(false);

		$this->assertSame('Nextcloud refused the account', $this->user()->create('resident', 'S', 'p'));
	}//end testARefusedAccountSaysSo()

	public function testAFailedCreationIsLoggedAndItsReasonReturned(): void {
		$this->users->method('createUser')->willThrowException(new RuntimeException('password too weak'));
		$this->logger->expects($this->once())->method('warning')
			->with('Portaliq: the example resident account was not created', ['reason' => 'password too weak']);

		$this->assertSame('password too weak', $this->user()->create('resident', 'S', 'p'));
	}//end testAFailedCreationIsLoggedAndItsReasonReturned()

	public function testDeletingAMissingAccountSucceeds(): void {
		$this->users->method('get')->with('resident')->willReturn(null);

		$this->assertTrue($this->user()->delete('resident'));
	}//end testDeletingAMissingAccountSucceeds()

	public function testDeletingAnAccountReportsItsResult(): void {
		$deleted = $this->createMock(IUser::class);
		$deleted->expects($this->once())->method('delete')->willReturn(true);
		$kept = $this->createMock(IUser::class);
		$kept->method('delete')->willReturn(false);
		$this->users->method('get')->willReturnMap([['a', $deleted], ['b', $kept]]);

		$this->assertTrue($this->user()->delete('a'));
		$this->assertFalse($this->user()->delete('b'));
	}//end testDeletingAnAccountReportsItsResult()
}//end class
