<?php

/**
 * TargetedAudience, ObjectArrayRow and StaffActionGuard: three controller
 * traits, exercised through a small host class.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use JsonSerializable;
use OCA\Portaliq\Controller\ObjectArrayRow;
use OCA\Portaliq\Controller\StaffActionGuard;
use OCA\Portaliq\Controller\TargetedAudience;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The controller traits.
 */
class ControllerTraitsTest extends TestCase {
	/**
	 * A host that opens the three traits.
	 *
	 * @param IUserSession|null $userSession The user session.
	 * @param ActionAuthServiceInterface|null $actionAuth The action authorisation.
	 *
	 * @return object
	 */
	private function host(?IUserSession $userSession=null, ?ActionAuthServiceInterface $actionAuth=null): object {
		return new class($userSession, $actionAuth) {
			use TargetedAudience;
			use ObjectArrayRow;
			use StaffActionGuard;

			private const ACTION = 'portaliq.messages.send';

			/**
			 * Hold the collaborators.
			 *
			 * @param IUserSession|null $userSession The user session.
			 * @param ActionAuthServiceInterface|null $actionAuth The action authorisation.
			 */
			public function __construct(private ?IUserSession $userSession, private ?ActionAuthServiceInterface $actionAuth) {
			}//end __construct()

			/**
			 * Whether a target names anyone.
			 *
			 * @param array<string, mixed> $target The target.
			 *
			 * @return bool
			 */
			public function targets(array $target): bool {
				return $this->hasAnyTarget(target: $target);
			}//end targets()

			/**
			 * A row as an array.
			 *
			 * @param mixed $row The row.
			 *
			 * @return array<string, mixed>
			 */
			public function row(mixed $row): array {
				return $this->normalise(row: $row);
			}//end row()

			/**
			 * The signed-in staff member's uid.
			 *
			 * @return string
			 */
			public function staff(): string {
				return $this->requireAuthenticatedStaff();
			}//end staff()
		};
	}//end host()

	/**
	 * Targets and whether they name anyone.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function targets(): array {
		return [
			'a school'          => [['schoolRef' => 's1'], true],
			'a group'           => [['groupRefs' => ['g1']], true],
			'a child'           => [['childRefs' => ['c1']], true],
			'nothing'           => [[], false],
			'an empty school'   => [['schoolRef' => ''], false],
			'empty lists'       => [['groupRefs' => [], 'childRefs' => []], false],
			'a school as list'  => [['schoolRef' => ['s1']], false],
			'groups as string'  => [['groupRefs' => 'g1'], false],
		];
	}//end targets()

	/**
	 * A message is targeted when it names a school, a group or a child.
	 *
	 * @param array<string, mixed> $target The target.
	 * @param bool $expected Whether it names anyone.
	 *
	 * @return void
	 */
	#[DataProvider('targets')]
	public function testATargetMustNameSomeone(array $target, bool $expected): void {
		$this->assertSame(expected: $expected, actual: $this->host()->targets(target: $target));
	}//end testATargetMustNameSomeone()

	/**
	 * Rows are arrays, serialisable objects become their array, anything else is empty.
	 *
	 * @return void
	 */
	public function testRowsBecomeArrays(): void {
		$host   = $this->host();
		$entity = new class implements JsonSerializable {
			/**
			 * The entity as data.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e1'];
			}//end jsonSerialize()
		};
		$scalar = new class implements JsonSerializable {
			/**
			 * Not an array.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'e1';
			}//end jsonSerialize()
		};
		$this->assertSame(expected: ['id' => 'r1'], actual: $host->row(row: ['id' => 'r1']));
		$this->assertSame(expected: ['id' => 'e1'], actual: $host->row(row: $entity));
		$this->assertSame(expected: [], actual: $host->row(row: $scalar));
		$this->assertSame(expected: [], actual: $host->row(row: new \stdClass()));
		$this->assertSame(expected: [], actual: $host->row(row: 'r1'));
	}//end testRowsBecomeArrays()

	/**
	 * A signed-in staff member with the action passes and is named by uid.
	 *
	 * @return void
	 */
	public function testAStaffMemberWithTheActionPasses(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anne');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$auth = $this->createMock(originalClassName: ActionAuthServiceInterface::class);
		$auth->expects($this->once())->method('requireAction')->with($user, 'portaliq.messages.send');
		$this->assertSame(expected: 'anne', actual: $this->host(userSession: $session, actionAuth: $auth)->staff());
	}//end testAStaffMemberWithTheActionPasses()

	/**
	 * Nobody signed in is refused before the action is even checked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsRefused(): void {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$auth = $this->createMock(originalClassName: ActionAuthServiceInterface::class);
		$auth->expects($this->never())->method('requireAction');
		$this->expectException(exception: OCSForbiddenException::class);
		$this->host(userSession: $session, actionAuth: $auth)->staff();
	}//end testNobodySignedInIsRefused()

	/**
	 * A refusal from the action check reaches the caller.
	 *
	 * @return void
	 */
	public function testARefusedActionIsNotSwallowed(): void {
		$user    = $this->createMock(originalClassName: IUser::class);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$auth = $this->createMock(originalClassName: ActionAuthServiceInterface::class);
		$auth->method('requireAction')->willThrowException(new OCSForbiddenException('no'));
		$this->expectException(exception: OCSForbiddenException::class);
		$this->host(userSession: $session, actionAuth: $auth)->staff();
	}//end testARefusedActionIsNotSwallowed()
}//end class
