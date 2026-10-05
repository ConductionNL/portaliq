<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\ClaimAttempts;
use OCA\Portaliq\Service\Identity\InvitationCode;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * invitation-secret-joins-the-signed-in-account REQ-PIS-007 and REQ-PIS-008:
 * a waiting account gets a one-time secret of which only the hash is stored,
 * and the signed-in person who hands it back takes over the waiting
 * account's claims. Wrong, expired and used are one answer, and wrong
 * secrets lock the route for the account.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class WaitingAccountInvitationTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * The audit facts recorded.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $audited = [];

	/**
	 * The fake cache's content.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * When set, the fake cache throws: an instance without a usable cache.
	 *
	 * @var bool
	 */
	private bool $cacheBroken = false;

	protected function setUp(): void {
		$this->rows = [];
		$this->audited = [];
		$this->cached = [];
		$this->cacheBroken = false;

	}//end setUp()

	public function testOnlyTheHashOfTheSecretIsStoredWithAWeekToUseIt(): void {
		$waiting = $this->seedWaiting();
		$now = new DateTimeImmutable('2026-10-05T09:00:00+00:00');

		$issued = $this->service()->issue(subjectRef: 'waiting-1', appId: 'learniq', now: $now);

		$this->assertSame('secret-1', $issued['token']);
		$this->assertSame('ouder@example.org', $issued['email']);
		$this->assertSame('gemeente-x', $issued['organisation']);
		$this->assertSame('2026-10-12T09:00:00+00:00', $issued['expiresAt']);
		$this->assertSame(hash('sha256', 'secret-1'), $this->rows[$waiting]['claimTokenHash']);
		$this->assertSame('2026-10-12T09:00:00+00:00', $this->rows[$waiting]['claimExpiresAt']);
		$this->assertStringNotContainsString('secret-1', (string)json_encode($this->rows));

	}//end testOnlyTheHashOfTheSecretIsStoredWithAWeekToUseIt()

	public function testOnlyTheAppThatProvisionedAWaitingAccountMayInviteForIt(): void {
		$this->seedWaiting();
		$this->seedWaiting(['subjectRef' => 'active-1', 'status' => 'active']);
		$this->seedWaiting(['subjectRef' => 'identity-1', 'identityType' => 'digid', 'identityRef' => 'bsn-9']);
		$this->seedWaiting(['subjectRef' => 'no-mail-1', 'email' => '']);
		$service = $this->service();

		$this->assertNull($service->issue(subjectRef: 'waiting-1', appId: 'dossiq'), 'another app');
		$this->assertNull($service->issue(subjectRef: 'waiting-1', appId: ''), 'no app');
		$this->assertNull($service->issue(subjectRef: 'active-1', appId: 'learniq'), 'not pending');
		$this->assertNull($service->issue(subjectRef: 'identity-1', appId: 'learniq'), 'on an identity reference');
		$this->assertNull($service->issue(subjectRef: 'no-mail-1', appId: 'learniq'), 'no address to mail');
		$this->assertNull($service->issue(subjectRef: 'nobody', appId: 'learniq'), 'unknown');

	}//end testOnlyTheAppThatProvisionedAWaitingAccountMayInviteForIt()

	public function testTheSignedInPersonTakesOverTheWaitingAccount(): void {
		$waiting = $this->seedWaiting();
		$account = $this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$result = $service->redeem(subject: $this->subject(), secret: $issued['token']);

		$this->assertSame(WaitingAccountInvitation::CLAIMED, $result);
		$this->assertSame('guardian-7', $this->rows[$account]['claims']['learniq']['guardianRef']);
		$this->assertSame('ouder@example.org', $this->rows[$account]['email'], 'The invited address becomes hers when she has none.');
		$this->assertSame('void', $this->rows[$waiting]['status']);
		$this->assertSame(WaitingAccountInvitation::VOID_REASON, $this->rows[$waiting]['voidReason']);
		$this->assertSame('', $this->rows[$waiting]['claimTokenHash']);
		// Who, which account, in which session.
		$this->assertSame([['claim', 'subject-1', 'gemeente-x', 'portaliq', 'portalAccount', $waiting, 'jti-1']], $this->audited);

	}//end testTheSignedInPersonTakesOverTheWaitingAccount()

	public function testTheSecretWorksOnce(): void {
		$this->seedWaiting();
		$this->seedSignedIn();
		$other = $this->seedSignedIn(['subjectRef' => 'subject-2', 'identityRef' => 'bsn-2']);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$service->redeem(subject: $this->subject(), secret: $issued['token']);

		$again = $service->redeem(subject: $this->subject(['subjectRef' => 'subject-2', 'jti' => 'jti-2']), secret: $issued['token']);

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $again);
		$this->assertArrayNotHasKey('claims', $this->rows[$other]);
		$this->assertCount(1, $this->audited);

	}//end testTheSecretWorksOnce()

	public function testWrongExpiredAndUsedAreOneAnswer(): void {
		$waiting = $this->seedWaiting();
		$this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq', now: new DateTimeImmutable('2026-10-05T09:00:00+00:00'));

		$wrong   = $service->redeem(subject: $this->subject(), secret: 'not-the-secret', now: new DateTimeImmutable('2026-10-06T09:00:00+00:00'));
		$empty   = $service->redeem(subject: $this->subject(), secret: '', now: new DateTimeImmutable('2026-10-06T09:00:00+00:00'));
		$expired = $service->redeem(subject: $this->subject(), secret: $issued['token'], now: new DateTimeImmutable('2026-10-12T09:00:00+00:00'));

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $wrong);
		$this->assertSame($wrong, $empty);
		$this->assertSame($wrong, $expired);
		$this->assertSame('pending', $this->rows[$waiting]['status']);
		$this->assertSame([], $this->audited);

		// One second before the expiry it still works.
		$this->rows['uuid-2']['claimAttempts'] = 0;
		$this->assertSame(
			WaitingAccountInvitation::CLAIMED,
			$service->redeem(subject: $this->subject(['jti' => 'jti-fresh']), secret: $issued['token'], now: new DateTimeImmutable('2026-10-12T08:59:59+00:00'))
		);

	}//end testWrongExpiredAndUsedAreOneAnswer()

	public function testAnInvitationOfAnotherOrganisationOpensNothing(): void {
		$waiting = $this->seedWaiting(['organisation' => 'gemeente-y']);
		$this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame('pending', $this->rows[$waiting]['status']);

	}//end testAnInvitationOfAnotherOrganisationOpensNothing()

	public function testAWaitingAccountThatIsNoLongerWaitingOpensNothing(): void {
		$waiting = $this->seedWaiting();
		$this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->rows[$waiting]['status'] = 'void';
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $issued['token']));

		$this->rows[$waiting]['status'] = 'pending';
		$this->rows[$waiting]['identityRef'] = 'bsn-somebody';
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(['jti' => 'jti-2']), secret: $issued['token']));

	}//end testAWaitingAccountThatIsNoLongerWaitingOpensNothing()

	public function testOnlyAnActiveAccountThatSignedInThroughAnIdentityProviderReceives(): void {
		$waiting = $this->seedWaiting();
		$this->seedSignedIn(['identityRef' => '']);
		$this->seedSignedIn(['subjectRef' => 'subject-3', 'status' => 'suspended']);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(['subjectRef' => 'subject-3']), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(['subjectRef' => 'nobody']), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(['organisation' => 'gemeente-y']), secret: $issued['token']));
		$this->assertSame('pending', $this->rows[$waiting]['status']);
		$this->assertSame(hash('sha256', $issued['token']), $this->rows[$waiting]['claimTokenHash'], 'A refused caller spends nothing.');

	}//end testOnlyAnActiveAccountThatSignedInThroughAnIdentityProviderReceives()

	public function testFiveWrongSecretsLockTheAccountEvenForTheRightOne(): void {
		$waiting = $this->seedWaiting();
		$account = $this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$start   = new DateTimeImmutable('2026-10-05T10:00:00+00:00');

		for ($i = 1; $i <= ClaimAttempts::PER_ACCOUNT; $i++) {
			// A new session each time: the account count is what locks.
			$this->assertSame(
				WaitingAccountInvitation::NOT_VALID,
				$service->redeem(subject: $this->subject(['jti' => 'jti-' . $i]), secret: 'guess-' . $i, now: $start->modify('+' . $i . ' minutes'))
			);
		}

		$this->assertSame(ClaimAttempts::PER_ACCOUNT, $this->rows[$account]['claimAttempts']);
		$this->assertSame('2026-10-05T10:01:00+00:00', $this->rows[$account]['claimAttemptsSince'], 'The window opens at the first wrong secret.');
		$locked = $service->redeem(subject: $this->subject(['jti' => 'jti-new']), secret: $issued['token'], now: $start->modify('+30 minutes'));
		$this->assertSame(WaitingAccountInvitation::LOCKED, $locked);
		$this->assertSame('pending', $this->rows[$waiting]['status']);

		// The window is an hour from the first wrong secret.
		$after = $service->redeem(subject: $this->subject(['jti' => 'jti-later']), secret: $issued['token'], now: $start->modify('+62 minutes'));
		$this->assertSame(WaitingAccountInvitation::CLAIMED, $after);
		$this->assertSame(0, $this->rows[$account]['claimAttempts']);

	}//end testFiveWrongSecretsLockTheAccountEvenForTheRightOne()

	public function testTheAccountLockHoldsWithoutACache(): void {
		$this->cacheBroken = true;
		$this->seedWaiting();
		$this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		for ($i = 1; $i <= ClaimAttempts::PER_ACCOUNT; $i++) {
			$service->redeem(subject: $this->subject(), secret: 'guess-' . $i);
		}

		$this->assertSame(WaitingAccountInvitation::LOCKED, $service->redeem(subject: $this->subject(), secret: $issued['token']));

	}//end testTheAccountLockHoldsWithoutACache()

	public function testFiveWrongSecretsLockTheSession(): void {
		$this->seedWaiting();
		$account = $this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		for ($i = 1; $i <= ClaimAttempts::PER_SESSION; $i++) {
			$service->redeem(subject: $this->subject(), secret: 'guess-' . $i);
			// The account count is wiped, so only the session count can lock.
			$this->rows[$account]['claimAttempts'] = 0;
		}

		$this->assertSame(WaitingAccountInvitation::LOCKED, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::CLAIMED, $service->redeem(subject: $this->subject(['jti' => 'another-session']), secret: $issued['token']));

	}//end testFiveWrongSecretsLockTheSession()

	public function testInvitingAgainReplacesTheEarlierSecret(): void {
		$this->seedWaiting();
		$this->seedSignedIn();
		$service = $this->service();
		$first   = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$second  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertNotSame($first['token'], $second['token']);
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $first['token']));
		$this->assertSame(WaitingAccountInvitation::CLAIMED, $service->redeem(subject: $this->subject(), secret: $second['token']));

	}//end testInvitingAgainReplacesTheEarlierSecret()

	public function testAClaimTheAccountHoldsIsKeptAndSoIsItsOwnAddress(): void {
		$this->seedWaiting(['claims' => ['learniq' => ['guardianRef' => 'guardian-2', 'schoolRef' => 'school-9']]]);
		$account = $this->seedSignedIn(['email' => 'eigen@example.org', 'claims' => ['learniq' => ['guardianRef' => 'guardian-1']]]);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$service->redeem(subject: $this->subject(), secret: $issued['token']);

		$this->assertSame(['guardianRef' => 'guardian-1', 'schoolRef' => 'school-9'], $this->rows[$account]['claims']['learniq']);
		$this->assertSame('eigen@example.org', $this->rows[$account]['email']);

	}//end testAClaimTheAccountHoldsIsKeptAndSoIsItsOwnAddress()

	/**
	 * invitation-code-from-a-letter REQ-PIS-009: the short code is the same
	 * secret in a form a person can type. Only its hash is stored.
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function testACodeForALetterIsStoredAsAHashAndShownInGroups(): void {
		$waiting = $this->seedWaiting();
		$now = new DateTimeImmutable('2026-10-05T09:00:00+00:00');

		$issued = $this->service()->issueCode(subjectRef: 'waiting-1', appId: 'learniq', now: $now);

		$this->assertSame(['code' => 'ABCD-EFGH-2345', 'expiresAt' => '2026-10-12T09:00:00+00:00'], $issued);
		$this->assertSame(hash('sha256', 'ABCDEFGH2345'), $this->rows[$waiting]['claimCodeHash']);
		$this->assertStringNotContainsString('ABCDEFGH2345', (string)json_encode($this->rows));
		$this->assertStringNotContainsString('ABCD-EFGH-2345', (string)json_encode($this->rows));

	}//end testACodeForALetterIsStoredAsAHashAndShownInGroups()

	public function testOnlyTheAppThatProvisionedAWaitingAccountGetsACode(): void {
		$this->seedWaiting();
		$this->seedWaiting(['subjectRef' => 'active-1', 'status' => 'active']);
		$this->seedWaiting(['subjectRef' => 'identity-1', 'identityType' => 'digid', 'identityRef' => 'bsn-9']);
		$service = $this->service();

		$this->assertNull($service->issueCode(subjectRef: 'waiting-1', appId: 'dossiq'));
		$this->assertNull($service->issueCode(subjectRef: 'waiting-1', appId: ''));
		$this->assertNull($service->issueCode(subjectRef: 'active-1', appId: 'learniq'));
		$this->assertNull($service->issueCode(subjectRef: 'identity-1', appId: 'learniq'));
		$this->assertNull($service->issueCode(subjectRef: 'nobody', appId: 'learniq'));

	}//end testOnlyTheAppThatProvisionedAWaitingAccountGetsACode()

	public function testTheCodeIsRedeemedHoweverItIsTyped(): void {
		foreach (['ABCD-EFGH-2345', 'abcdefgh2345', ' abcd efgh 2345 ', 'AbCd-efGH-2345'] as $typed) {
			$this->setUp();
			$waiting = $this->seedWaiting();
			$account = $this->seedSignedIn();
			$service = $this->service();
			$service->issueCode(subjectRef: 'waiting-1', appId: 'learniq');

			$this->assertSame(WaitingAccountInvitation::CLAIMED, $service->redeem(subject: $this->subject(), secret: $typed), $typed);
			$this->assertSame('guardian-7', $this->rows[$account]['claims']['learniq']['guardianRef'], $typed);
			$this->assertSame('void', $this->rows[$waiting]['status'], $typed);
			$this->assertSame('', $this->rows[$waiting]['claimCodeHash'], $typed);
			$this->assertSame([['claim', 'subject-1', 'gemeente-x', 'portaliq', 'portalAccount', $waiting, 'jti-1']], $this->audited, $typed);
		}

	}//end testTheCodeIsRedeemedHoweverItIsTyped()

	public function testACodeWorksOnceAndExpiresLikeALink(): void {
		$waiting = $this->seedWaiting();
		$this->seedSignedIn();
		$service = $this->service();
		$service->issueCode(subjectRef: 'waiting-1', appId: 'learniq', now: new DateTimeImmutable('2026-10-05T09:00:00+00:00'));

		$expired = $service->redeem(subject: $this->subject(), secret: 'ABCD-EFGH-2345', now: new DateTimeImmutable('2026-10-12T09:00:00+00:00'));
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $expired);
		$this->assertSame('pending', $this->rows[$waiting]['status']);

		$this->assertSame(WaitingAccountInvitation::CLAIMED, $service->redeem(subject: $this->subject(), secret: 'ABCD-EFGH-2345', now: new DateTimeImmutable('2026-10-11T09:00:00+00:00')));
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: 'ABCD-EFGH-2345', now: new DateTimeImmutable('2026-10-11T09:01:00+00:00')));

	}//end testACodeWorksOnceAndExpiresLikeALink()

	public function testAWrongCodeCountsAndFiveLockTheAccount(): void {
		$waiting = $this->seedWaiting();
		$account = $this->seedSignedIn();
		$service = $this->service();
		$service->issueCode(subjectRef: 'waiting-1', appId: 'learniq');

		foreach (['ABCD-EFGH-2346', 'ZZZZ-ZZZZ-ZZZZ', 'ABCD-EFGH-234', 'ABCD-EFGH-2340', 'QQQQ-QQQQ-QQQQ'] as $index => $guess) {
			$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(['jti' => 'jti-' . $index]), secret: $guess), $guess);
		}

		$this->assertSame(ClaimAttempts::PER_ACCOUNT, $this->rows[$account]['claimAttempts']);
		$this->assertSame(WaitingAccountInvitation::LOCKED, $service->redeem(subject: $this->subject(['jti' => 'jti-new']), secret: 'ABCD-EFGH-2345'));
		$this->assertSame('pending', $this->rows[$waiting]['status']);

	}//end testAWrongCodeCountsAndFiveLockTheAccount()

	public function testThereIsOneLiveSecretALinkEndsACodeAndACodeEndsALink(): void {
		$waiting = $this->seedWaiting();
		$this->seedSignedIn();
		$service = $this->service();

		$link = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$service->issueCode(subjectRef: 'waiting-1', appId: 'learniq');
		$this->assertSame('', $this->rows[$waiting]['claimTokenHash']);
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $link['token']));

		$second = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$this->assertSame('', $this->rows[$waiting]['claimCodeHash']);
		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: 'ABCD-EFGH-2345'));
		$this->assertSame(WaitingAccountInvitation::CLAIMED, $service->redeem(subject: $this->subject(), secret: $second['token']));

	}//end testThereIsOneLiveSecretALinkEndsACodeAndACodeEndsALink()

	public function testACodeOfAnotherOrganisationOpensNothing(): void {
		$waiting = $this->seedWaiting(['organisation' => 'gemeente-y']);
		$this->seedSignedIn();
		$service = $this->service();
		$service->issueCode(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: 'ABCD-EFGH-2345'));
		$this->assertSame('pending', $this->rows[$waiting]['status']);

	}//end testACodeOfAnotherOrganisationOpensNothing()

	/**
	 * A random double: a predictable code when asked for the code alphabet,
	 * the trait's predictable secrets otherwise.
	 *
	 * @return ISecureRandom
	 */
	private function codeAwareRandom(): ISecureRandom {
		$counter = 0;
		$random  = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length, string $characters = '') use (&$counter): string {
				if ($characters === InvitationCode::ALPHABET) {
					return substr('ABCDEFGH2345', 0, $length);
				}

				$counter++;
				return 'secret-' . $counter;
			}
		);

		return $random;
	}//end codeAwareRandom()

	/**
	 * A waiting account: pending, address-only, provisioned by learniq.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return string The row's uuid.
	 */
	private function seedWaiting(array $overrides = []): string {
		return $this->seedRow('portalAccount', array_merge([
			'subjectRef' => 'waiting-1',
			'organisation' => 'gemeente-x',
			'audience' => 'parent',
			'email' => 'ouder@example.org',
			'verifiedEmail' => true,
			'status' => 'pending',
			'provisionedBy' => 'learniq',
			'claims' => ['learniq' => ['guardianRef' => 'guardian-7']],
		], $overrides));
	}//end seedWaiting()

	/**
	 * The account of a person who signed in through the broker: an identity
	 * reference and no address.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return string The row's uuid.
	 */
	private function seedSignedIn(array $overrides = []): string {
		return $this->seedRow('portalAccount', array_merge([
			'subjectRef' => 'subject-1',
			'organisation' => 'gemeente-x',
			'audience' => 'parent',
			'identityType' => 'digid',
			'identityRef' => 'bsn-1',
			'status' => 'active',
		], $overrides));
	}//end seedSignedIn()

	/**
	 * A session as PortalSessionService resolves it.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private function subject(array $overrides = []): array {
		return array_merge(['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x', 'trust' => 'substantial', 'jti' => 'jti-1'], $overrides);
	}//end subject()

	/**
	 * The service over the fake store, a recording audit trail and a cache.
	 *
	 * @return WaitingAccountInvitation
	 */
	private function service(): WaitingAccountInvitation {
		$auditor = $this->getMockBuilder(AuditTrailService::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$auditor->method('record')->willReturnCallback(
			function (string $verb, string $subjectRef, string $organisation, string $register, string $schema, string $id, string $jti = ''): void {
				$this->audited[] = [$verb, $subjectRef, $organisation, $register, $schema, $id, $jti];
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cached[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturnCallback(
			function () use ($cache): ICache {
				if ($this->cacheBroken === true) {
					throw new \RuntimeException('no cache');
				}

				return $cache;
			}
		);

		$writer = $this->fakeWriter();

		return new WaitingAccountInvitation($this->fakeReader(), $writer, $this->codeAwareRandom(), new ClaimAttempts($writer, $factory), $auditor);
	}//end service()
}//end class
