<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\ClaimAttempts;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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

	/**
	 * The locks the fake locking provider holds, by path.
	 *
	 * @var array<string, bool>
	 */
	private array $held = [];

	/**
	 * Called once when a lock is asked for, before it is granted.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $beforeLock = null;

	protected function setUp(): void {
		$this->rows = [];
		$this->audited = [];
		$this->cached = [];
		$this->cacheBroken = false;
		$this->held = [];
		$this->beforeLock = null;
		$this->afterRead = null;
		$this->readerIgnoresOrganisation = false;

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

	/**
	 * The store hands back the other organisation's row as well (the reader's
	 * filter is trusted for the query, not for the answer), so only the
	 * service's own organisation check stands between the two: the secret
	 * reads as wrong, counts as a wrong try, and nothing is spent.
	 *
	 * @return void
	 */
	public function testAnInvitationOfAnotherOrganisationOpensNothing(): void {
		$this->readerIgnoresOrganisation = true;
		$waiting = $this->seedWaiting(['organisation' => 'gemeente-y']);
		$account = $this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame('pending', $this->rows[$waiting]['status']);
		$this->assertSame(hash('sha256', $issued['token']), $this->rows[$waiting]['claimTokenHash']);
		$this->assertSame(1, $this->rows[$account]['claimAttempts'], 'Another organisation\'s secret is a wrong secret here.');

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

		// Security review L6: the answer is about the caller's own account,
		// and it is the same for a right and a wrong secret.
		$this->assertSame(WaitingAccountInvitation::CANNOT_RECEIVE, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::CANNOT_RECEIVE, $service->redeem(subject: $this->subject(), secret: 'not-the-secret'));
		$this->assertSame(WaitingAccountInvitation::CANNOT_RECEIVE, $service->redeem(subject: $this->subject(['subjectRef' => 'subject-3']), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::CANNOT_RECEIVE, $service->redeem(subject: $this->subject(['subjectRef' => 'nobody']), secret: $issued['token']));
		$this->assertSame(WaitingAccountInvitation::CANNOT_RECEIVE, $service->redeem(subject: $this->subject(['organisation' => 'gemeente-y']), secret: $issued['token']));
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

	/**
	 * Security review M1: an invitation that carries a claim the account
	 * holds with another value is refused before the secret is spent. The
	 * invitation stays whole for its real holder, nothing is counted and
	 * nothing is recorded. A claim with the same value is no conflict, and
	 * an address the account has is kept.
	 *
	 * @return void
	 */
	public function testAConflictingClaimIsRefusedBeforeTheSecretIsSpent(): void {
		$waiting = $this->seedWaiting(['claims' => ['learniq' => ['guardianRef' => 'guardian-2', 'schoolRef' => 'school-9']]]);
		$account = $this->seedSignedIn(['email' => 'eigen@example.org', 'claims' => ['learniq' => ['guardianRef' => 'guardian-1']]]);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::CONFLICT, $service->redeem(subject: $this->subject(), secret: $issued['token']));

		$this->assertSame(['guardianRef' => 'guardian-1'], $this->rows[$account]['claims']['learniq']);
		$this->assertSame('pending', $this->rows[$waiting]['status']);
		$this->assertSame(hash('sha256', $issued['token']), $this->rows[$waiting]['claimTokenHash'], 'The invitation is not spent.');
		$this->assertArrayNotHasKey('claimAttempts', $this->rows[$account]);
		$this->assertSame([], $this->audited);

		$this->setUp();
		$this->seedWaiting(['claims' => ['learniq' => ['guardianRef' => 'guardian-7', 'schoolRef' => 'school-9']]]);
		$account = $this->seedSignedIn(['email' => 'eigen@example.org', 'claims' => ['learniq' => ['guardianRef' => 'guardian-7']]]);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::CLAIMED, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame(['guardianRef' => 'guardian-7', 'schoolRef' => 'school-9'], $this->rows[$account]['claims']['learniq']);
		$this->assertSame('eigen@example.org', $this->rows[$account]['email']);

	}//end testAConflictingClaimIsRefusedBeforeTheSecretIsSpent()

	/**
	 * Security review M3: an account of another audience in the same
	 * organisation (a supplier) cannot take over a parent's invitation, and
	 * the invitation is not spent.
	 *
	 * @return void
	 */
	public function testAnAccountOfAnotherAudienceCannotTakeOverTheInvitation(): void {
		$waiting = $this->seedWaiting();
		$account = $this->seedSignedIn(['audience' => 'supplier', 'identityType' => 'eherkenning', 'identityRef' => 'kvk-1']);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');

		$this->assertSame(WaitingAccountInvitation::NOT_VALID, $service->redeem(subject: $this->subject(), secret: $issued['token']));

		$this->assertArrayNotHasKey('claims', $this->rows[$account]);
		$this->assertSame('pending', $this->rows[$waiting]['status']);
		$this->assertSame(hash('sha256', $issued['token']), $this->rows[$waiting]['claimTokenHash']);

	}//end testAnAccountOfAnotherAudienceCannotTakeOverTheInvitation()

	/**
	 * Security review M2: two people post the same secret at the same moment.
	 * The second request runs entirely between the first one's read and its
	 * write. Exactly one join happens: the first request reads the waiting
	 * account again under its lock, finds the secret spent and joins nothing.
	 *
	 * @return void
	 */
	public function testTwoRedeemsOfOneSecretJoinExactlyOnce(): void {
		$waiting = $this->seedWaiting();
		$first   = $this->seedSignedIn();
		$second  = $this->seedSignedIn(['subjectRef' => 'subject-2', 'identityRef' => 'bsn-2']);
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$results = [];

		$this->afterRead = function (string $scopeField) use ($service, $issued, &$results): bool {
			if ($scopeField !== 'claimTokenHash') {
				return true;
			}

			$results['second'] = $service->redeem(subject: $this->subject(['subjectRef' => 'subject-2', 'jti' => 'jti-2']), secret: $issued['token']);
			return false;
		};
		$results['first'] = $service->redeem(subject: $this->subject(), secret: $issued['token']);

		$this->assertSame(['second' => WaitingAccountInvitation::CLAIMED, 'first' => WaitingAccountInvitation::NOT_VALID], $results);
		$this->assertCount(1, $this->audited, 'One join recorded.');
		$this->assertSame('guardian-7', $this->rows[$second]['claims']['learniq']['guardianRef']);
		$this->assertArrayNotHasKey('claims', $this->rows[$first]);
		$this->assertSame('void', $this->rows[$waiting]['status']);

	}//end testTwoRedeemsOfOneSecretJoinExactlyOnce()

	/**
	 * While another request holds the waiting account's lock past the wait,
	 * a redeem answers busy and changes nothing: no spend, no count.
	 *
	 * @return void
	 */
	public function testARedeemWhileTheWaitingAccountIsLockedChangesNothing(): void {
		$waiting = $this->seedWaiting();
		$account = $this->seedSignedIn();
		$service = $this->service();
		$issued  = $service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$this->held['portaliq/claim/' . $waiting] = true;

		$this->assertSame(WaitingAccountInvitation::BUSY, $service->redeem(subject: $this->subject(), secret: $issued['token']));
		$this->assertSame('pending', $this->rows[$waiting]['status']);
		$this->assertSame(hash('sha256', $issued['token']), $this->rows[$waiting]['claimTokenHash']);
		$this->assertArrayNotHasKey('claimAttempts', $this->rows[$account]);
		$this->assertSame(['portaliq/claim/' . $waiting => true], $this->held, 'The caller\'s own lock was given back.');

	}//end testARedeemWhileTheWaitingAccountIsLockedChangesNothing()

	/**
	 * Security review L1: two wrong secrets from one account at the same
	 * moment are counted as two. The second request runs entirely between
	 * the first one's first read of the account and its count; the first
	 * reads the account again under its lock and counts on top.
	 *
	 * @return void
	 */
	public function testTwoWrongSecretsAtOnceCountAsTwo(): void {
		$this->seedWaiting();
		$account = $this->seedSignedIn();
		$service = $this->service();
		$service->issue(subjectRef: 'waiting-1', appId: 'learniq');
		$now = new DateTimeImmutable('2026-10-05T10:00:00+00:00');

		$this->afterRead = function (string $scopeField) use ($service, $now): bool {
			if ($scopeField !== 'subjectRef') {
				return true;
			}

			$service->redeem(subject: $this->subject(['jti' => 'jti-2']), secret: 'guess-2', now: $now);
			return false;
		};
		$service->redeem(subject: $this->subject(), secret: 'guess-1', now: $now);

		$this->assertSame(2, $this->rows[$account]['claimAttempts']);

	}//end testTwoWrongSecretsAtOnceCountAsTwo()

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

		return new WaitingAccountInvitation($this->fakeReader(), $writer, $this->fakeRandom(), new ClaimAttempts($writer, $factory), $auditor, new ClaimLock($this->fakeLocks(), 0));
	}//end service()

	/**
	 * A locking provider that holds exclusive locks in memory and refuses a
	 * taken one, the way Nextcloud's does.
	 *
	 * @return ILockingProvider
	 */
	private function fakeLocks(): ILockingProvider {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			function (string $path): void {
				$hook = $this->beforeLock;
				if ($hook !== null) {
					$this->beforeLock = null;
					$hook($path);
				}

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
	}//end fakeLocks()
}//end class
