<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\Portaliq\Service\Proposals\ChangeProposalsProvider;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * change-proposal-queue REQ-CPQ-004: the data leaf lists the queue only to a
 * reviewer, records a colleague's proposal as that colleague, and offers no
 * way to edit or delete a proposal through a host app.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ChangeProposalsProviderTest extends TestCase {

	/**
	 * The queue double.
	 *
	 * @var ProposalService&\PHPUnit\Framework\MockObject\MockObject
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

	public function testItIsTheOpenRegisterContractUnderTheLeafId(): void {
		$provider = $this->provider(user: $this->user('handler-anna'));

		$this->assertInstanceOf(IntegrationProvider::class, $provider);
		$this->assertSame('portaliq-change-proposals', $provider->getId());
		$this->assertSame('app-local', $provider->getStorageStrategy());
		$this->assertNull($provider->getOpenConnectorSource());

	}//end testItIsTheOpenRegisterContractUnderTheLeafId()

	public function testTheQueueIsEmptyForSomeoneWhoMayNotReview(): void {
		$provider = $this->provider(user: $this->user('colleague-bob'), mayReview: false);
		$this->proposals->expects($this->never())->method('forSubject');

		$this->assertSame([], $provider->list(register: 'dossiq', schema: 'zaak', objectId: 'zaak-1'));

	}//end testTheQueueIsEmptyForSomeoneWhoMayNotReview()

	public function testTheQueueIsEmptyWithNobodySignedIn(): void {
		$provider = $this->provider(user: null);
		$this->proposals->expects($this->never())->method('forSubject');

		$this->assertSame([], $provider->list(register: 'dossiq', schema: 'zaak', objectId: 'zaak-1'));

	}//end testTheQueueIsEmptyWithNobodySignedIn()

	public function testAReviewerGetsTheQueuedProposalsOnThatRecord(): void {
		$provider = $this->provider(user: $this->user('handler-anna'));
		$this->proposals->expects($this->once())
			->method('forSubject')
			->with($this->equalTo(['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1']))
			->willReturn([['uuid' => 'p-1', 'state' => 'queued']]);

		$this->assertSame([['uuid' => 'p-1', 'state' => 'queued']], $provider->list(register: 'dossiq', schema: 'zaak', objectId: 'zaak-1'));

	}//end testAReviewerGetsTheQueuedProposalsOnThatRecord()

	public function testAColleagueProposesAsThemselvesNeverAsThePayloadSays(): void {
		$provider = $this->provider(user: $this->user('colleague-bob'), mayReview: false);
		$this->proposals->expects($this->once())
			->method('propose')
			->with(
				$this->equalTo(['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1']),
				$this->equalTo([['property' => 'applicantPhone', 'proposedValue' => '0687654321']]),
				$this->equalTo(['applicantPhone']),
				$this->equalTo([]),
				$this->equalTo('colleague-bob'),
				$this->equalTo('staff'),
				$this->equalTo('New number')
			)
			->willReturn(['proposal' => ['uuid' => 'p-2', 'state' => 'queued']]);

		$created = $provider->create(
			register: 'dossiq',
			schema: 'zaak',
			objectId: 'zaak-1',
			payload: [
				'changes' => [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
				'proposable' => ['applicantPhone'],
				'note' => 'New number',
				'proposedBy' => 'somebody-else',
			]
		);

		$this->assertSame(['uuid' => 'p-2', 'state' => 'queued'], $created);

	}//end testAColleagueProposesAsThemselvesNeverAsThePayloadSays()

	public function testAColleagueWhoCannotReadTheRecordIsRefused(): void {
		$provider = $this->provider(user: $this->user('colleague-bob'), mayReview: false, mayRead: false);
		$this->proposals->expects($this->never())->method('propose');

		$this->expectException(InvalidArgumentException::class);
		$provider->create(register: 'dossiq', schema: 'zaak', objectId: 'zaak-1', payload: ['changes' => [['property' => 'applicantPhone']], 'proposable' => ['applicantPhone']]);

	}//end testAColleagueWhoCannotReadTheRecordIsRefused()

	public function testARefusedProposalIsABadRequestNamingWhy(): void {
		$provider = $this->provider(user: $this->user('colleague-bob'));
		$this->proposals->method('propose')->willReturn(['error' => 'property_not_proposable', 'property' => 'status']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('property_not_proposable');
		$provider->create(register: 'dossiq', schema: 'zaak', objectId: 'zaak-1', payload: ['changes' => [['property' => 'status']], 'proposable' => []]);

	}//end testARefusedProposalIsABadRequestNamingWhy()

	public function testAProposalCannotBeReadEditedOrDeletedThroughAHost(): void {
		$provider = $this->provider(user: $this->user('handler-anna'));
		$refused = 0;
		foreach ([
			static fn () => $provider->get('dossiq', 'zaak', 'zaak-1', 'p-1'),
			static fn () => $provider->update('dossiq', 'zaak', 'zaak-1', 'p-1', ['state' => 'accepted']),
			static fn () => $provider->delete('dossiq', 'zaak', 'zaak-1', 'p-1'),
		] as $call) {
			try {
				$call();
			} catch (NotImplementedException $notOffered) {
				$refused++;
			}
		}

		$this->assertSame(3, $refused);

	}//end testAProposalCannotBeReadEditedOrDeletedThroughAHost()

	/**
	 * The provider under test.
	 *
	 * @param IUser|null $user      The signed-in user.
	 * @param bool       $mayReview Whether the guard allows reviewing.
	 * @param bool       $mayRead   Whether the guard allows reading.
	 *
	 * @return ChangeProposalsProvider
	 */
	private function provider(?IUser $user, bool $mayReview = true, bool $mayRead = true): ChangeProposalsProvider {
		$this->proposals = $this->getMockBuilder(ProposalService::class)
			->disableOriginalConstructor()
			->onlyMethods(['propose', 'forSubject'])
			->getMock();

		$guard = $this->getMockBuilder(PortalCaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['mayAct', 'mayRead'])
			->getMock();
		$guard->method('mayAct')->willReturn($mayReview);
		$guard->method('mayRead')->willReturn($mayRead);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

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
