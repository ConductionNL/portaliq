<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\Portaliq\Service\Proposals\ChangeProposalsProvider;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * change-proposal-queue REQ-CPQ-004: what the leaf tells OpenRegister about
 * itself, and how `create` treats a missing session and a payload whose lists
 * are not lists.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */
#[CoversClass(ChangeProposalsProvider::class)]
class ChangeProposalsProviderDescriptorTest extends TestCase {

	/**
	 * The queue double.
	 *
	 * @var ProposalService&MockObject
	 */
	private $proposals;

	/**
	 * Skip without OpenRegister's contract on the autoloader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (interface_exists(IntegrationProvider::class) === false) {
			$this->markTestSkipped('OpenRegister is not loadable: run inside Nextcloud or set PORTALIQ_OPENREGISTER_LIB.');
		}

	}//end setUp()

	public function testTheLeafDescribesItselfToOpenRegister(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Change proposals')->willReturn('Wijzigingsvoorstellen');
		$provider = $this->provider(user: null, l10n: $l10n);

		$this->assertSame('Wijzigingsvoorstellen', $provider->getLabel());
		$this->assertSame('FileDocumentEditOutline', $provider->getIcon());
		$this->assertSame('workflow', $provider->getGroup());
		$this->assertSame('portaliq', $provider->getRequiredApp());
		$this->assertTrue($provider->isEnabled());
		$this->assertNull($provider->requiresPermission());
		$this->assertSame(['type' => 'none'], $provider->authRequirements());
		$this->assertSame(['status' => 'ok', 'authStatus' => 'configured', 'message' => null], $provider->health());

	}//end testTheLeafDescribesItselfToOpenRegister()

	public function testNobodySignedInCannotPropose(): void {
		$provider = $this->provider(user: null);
		$this->proposals->expects($this->never())->method('propose');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not_authenticated');
		$provider->create(new ObjectRef(register: 'dossiq', schema: 'zaak', id: 'zaak-1'), payload: ['changes' => [['property' => 'applicantPhone']], 'proposable' => ['applicantPhone']]);

	}//end testNobodySignedInCannotPropose()

	public function testListsThatAreNotListsArriveEmptyAndKeyedListsArriveRenumbered(): void {
		$provider = $this->provider(user: $this->user('colleague-bob'));
		$this->proposals->expects($this->once())
			->method('propose')
			->with(
				$this->equalTo(['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1']),
				$this->identicalTo([]),
				$this->identicalTo(['7', 'applicantPhone']),
				$this->equalTo([]),
				$this->equalTo('colleague-bob'),
				$this->equalTo('staff'),
				$this->identicalTo('')
			)
			->willReturn(['error' => 'nothing_proposed']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('nothing_proposed');
		$provider->create(new ObjectRef(register: 'dossiq', schema: 'zaak', id: 'zaak-1'), payload: ['changes' => 'applicantPhone', 'proposable' => ['a' => 7, 'b' => 'applicantPhone']]);

	}//end testListsThatAreNotListsArriveEmptyAndKeyedListsArriveRenumbered()

	public function testARefusalWithoutAReasonIsRefused(): void {
		$provider = $this->provider(user: $this->user('colleague-bob'));
		$this->proposals->method('propose')->willReturn([]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('refused');
		$provider->create(new ObjectRef(register: 'dossiq', schema: 'zaak', id: 'zaak-1'), payload: []);

	}//end testARefusalWithoutAReasonIsRefused()

	/**
	 * The provider under test.
	 *
	 * @param IUser|null $user The signed-in user.
	 * @param IL10N|null $l10n The translator, or one that returns its argument.
	 *
	 * @return ChangeProposalsProvider
	 */
	private function provider(?IUser $user, ?IL10N $l10n = null): ChangeProposalsProvider {
		$this->proposals = $this->getMockBuilder(ProposalService::class)
			->disableOriginalConstructor()
			->onlyMethods(['propose', 'forSubject'])
			->getMock();

		$guard = $this->getMockBuilder(PortalCaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['mayAct', 'mayRead'])
			->getMock();
		$guard->method('mayAct')->willReturn(true);
		$guard->method('mayRead')->willReturn(true);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		if ($l10n === null) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
		}

		return new ChangeProposalsProvider($this->proposals, $guard, $userSession, $l10n);
	}//end provider()

	/**
	 * A user double with a uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

}//end class
