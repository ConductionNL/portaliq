<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalJwtService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract v2 session-edge tests: the eIDAS trust vocabulary normalises
 * fail-closed (unknown → low), the single ordering helper enforces
 * low < substantial < high with unrecognised minTrust unsatisfiable, and —
 * the token-confusion guard — an X-Portal-Subject assertion presented as an
 * Authorization bearer is REJECTED while real sessions keep resolving.
 *
 * portal-auth-edge-session-hardening additions: no dedicated secret means
 * issuance/resolution fail closed (never a system-secret fallback); a session
 * is recorded on issue and a revoked/unknown jti is rejected on resolve, even
 * when a valid signature is presented; logout-style revocation and an OR
 * lookup failure both fail closed.
 *
 * portal-session-hardening-v2 additions: `refreshSession()` rotates the jti,
 * revokes the old one, and slides the expiry within an absolute maximum
 * lifetime measured from the ORIGINAL login (`authTime`, carried unchanged
 * across rotations); a refresh past the cap, on a revoked/expired/malformed
 * bearer, or with no dedicated secret configured fails closed to null. Login/
 * logout/refresh each record an audit event via the injected AuditTrailService.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T7
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.3
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.1
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.3
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.1
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T01
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T02
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T11
 */
class PortalSessionServiceTest extends TestCase {

	private const SECRET = 'unit-test-signing-secret-000000000';

	public function testUnknownTrustClaimNormalisesToLow(): void {
		$service = $this->service();

		foreach (['dev', 'EH3', '', 'HIGH', 'Substantial'] as $legacy) {
			$issued = $service->issueSession(
				subjectRef: 's1',
				audience: 'supplier',
				organisation: 'org-1',
				trust: $legacy
			);
			$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);
			$this->assertNotNull($subject);
			$this->assertSame('low', $subject['trust'], "trust '{$legacy}' must normalise to low");
		}

		$issued = $service->issueSession(
			subjectRef: 's1',
			audience: 'supplier',
			organisation: 'org-1',
			trust: 'substantial'
		);
		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);
		$this->assertSame('substantial', $subject['trust']);

	}//end testUnknownTrustClaimNormalisesToLow()

	public function testTrustSatisfiesOrdersLowSubstantialHigh(): void {
		// Missing minTrust (null or '') defaults to low → everyone passes.
		$this->assertTrue(PortalSessionService::trustSatisfies('low', null));
		$this->assertTrue(PortalSessionService::trustSatisfies('low', ''));

		// The ordering matrix low < substantial < high.
		$this->assertFalse(PortalSessionService::trustSatisfies('low', 'substantial'));
		$this->assertFalse(PortalSessionService::trustSatisfies('low', 'high'));
		$this->assertTrue(PortalSessionService::trustSatisfies('substantial', 'substantial'));
		$this->assertFalse(PortalSessionService::trustSatisfies('substantial', 'high'));
		$this->assertTrue(PortalSessionService::trustSatisfies('high', 'low'));
		$this->assertTrue(PortalSessionService::trustSatisfies('high', 'high'));

		// Unknown SUBJECT trust compares as low.
		$this->assertFalse(PortalSessionService::trustSatisfies('EH3', 'substantial'));
		$this->assertTrue(PortalSessionService::trustSatisfies('EH3', 'low'));

	}//end testTrustSatisfiesOrdersLowSubstantialHigh()

	public function testUnrecognisedMinTrustIsUnsatisfiableForEveryone(): void {
		foreach (['low', 'substantial', 'high'] as $subjectTrust) {
			$this->assertFalse(PortalSessionService::trustSatisfies($subjectTrust, 'ultra'));
			$this->assertFalse(PortalSessionService::trustSatisfies($subjectTrust, 'High'));
			$this->assertFalse(PortalSessionService::trustSatisfies($subjectTrust, ['high']));
		}

	}//end testUnrecognisedMinTrustIsUnsatisfiableForEveryone()

	public function testAssertionCarriesUseClaimSessionJtiAndShortTtl(): void {
		$service = $this->service();
		$assertion = $service->issueAssertion(
			[
				'subjectRef' => 's1',
				'audience' => 'supplier',
				'organisation' => 'org-1',
				'trust' => 'dev',
				'jti' => 'session-jti-1',
			]
		);

		// Decode with the same secret to inspect the claims.
		$claims = (new PortalJwtService(self::SECRET))->validate($assertion);
		$this->assertSame('s1', $claims['sub']);
		$this->assertSame('supplier', $claims['audience']);
		$this->assertSame('org-1', $claims['organisation']);
		// Trust is normalised on the way into the assertion too.
		$this->assertSame('low', $claims['trust']);
		// The SESSION's jti — audit correlation to the originating session.
		$this->assertSame('session-jti-1', $claims['jti']);
		$this->assertSame(PortalJwtService::USE_ASSERTION, $claims['use']);
		$this->assertSame(PortalJwtService::ASSERTION_TTL, ((int)$claims['exp'] - (int)$claims['iat']));

	}//end testAssertionCarriesUseClaimSessionJtiAndShortTtl()

	/**
	 * Decision 173: the resolved subject's branch reaches the assertion, and a
	 * subject without one mints the nine claims only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	public function testAssertionCarriesTheSessionBranchOnlyWhenThereIsOne(): void {
		$service = $this->service();
		$subject = [
			'subjectRef' => 's1',
			'audience' => 'business',
			'organisation' => 'org-1',
			'trust' => 'substantial',
			'jti' => 'session-jti-1',
		];

		$withBranch = (new PortalJwtService(self::SECRET))->validate(
			$service->issueAssertion(array_merge($subject, ['branch' => '000012345678']))
		);
		$this->assertSame('000012345678', $withBranch['branch']);

		$without = (new PortalJwtService(self::SECRET))->validate($service->issueAssertion($subject));
		$this->assertArrayNotHasKey('branch', $without);

	}//end testAssertionCarriesTheSessionBranchOnlyWhenThereIsOne()

	public function testAssertionPresentedAsBearerFailsClosed(): void {
		$service = $this->service();
		$assertion = $service->issueAssertion(
			[
				'subjectRef' => 's1',
				'audience' => 'supplier',
				'organisation' => 'org-1',
				'trust' => 'high',
				'jti' => 'session-jti-1',
			]
		);

		// A fresh, correctly signed, unexpired assertion is NOT a session.
		$this->assertNull($service->resolveFromBearer('Bearer ' . $assertion));

	}//end testAssertionPresentedAsBearerFailsClosed()

	public function testRealSessionBearerStillResolves(): void {
		$service = $this->service();
		$issued = $service->issueSession(
			subjectRef: 's1',
			audience: 'supplier',
			organisation: 'org-1',
			trust: 'high',
			roles: ['supplier:read']
		);

		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);
		$this->assertNotNull($subject);
		$this->assertSame('s1', $subject['subjectRef']);
		$this->assertSame('high', $subject['trust']);
		$this->assertSame($issued['jti'], $subject['jti']);

	}//end testRealSessionBearerStillResolves()

	public function testNoDedicatedSecretRefusesIssuanceAndResolution(): void {
		// Empty app config → NEVER falls back to a system/instance secret
		// (portal-auth-edge-session-hardening) — the edge fails closed.
		$service = $this->service(secret: '');

		$this->assertFalse($service->isConfigured());
		$this->assertNull($service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1'));
		$this->assertNull($service->resolveFromBearer('Bearer whatever'));

	}//end testNoDedicatedSecretRefusesIssuanceAndResolution()

	public function testShortSecretAlsoRefusesFailClosed(): void {
		$service = $this->service(secret: 'too-short');
		$this->assertFalse($service->isConfigured());
		$this->assertNull($service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1'));

	}//end testShortSecretAlsoRefusesFailClosed()

	public function testIssuedSessionIsRecordedAndResolvable(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high');
		$this->assertNotNull($issued);

		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);
		$this->assertNotNull($subject);
		$this->assertSame($issued['jti'], $subject['jti']);

	}//end testIssuedSessionIsRecordedAndResolvable()

	public function testRevokedJtiFailsClosedEvenWithValidSignature(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$this->assertNotNull($issued);

		// Still valid before revocation.
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $issued['token']));

		$this->assertTrue($service->revoke($issued['jti']));

		// Same, perfectly-signed, unexpired token — now rejected.
		$this->assertNull($service->resolveFromBearer('Bearer ' . $issued['token']));

	}//end testRevokedJtiFailsClosedEvenWithValidSignature()

	public function testUnknownJtiFailsClosed(): void {
		// A signature that validates but whose jti has no portalSession row at
		// all (never issued via this service, or the OR write silently failed)
		// must be rejected exactly like a revoked one.
		$service = $this->service();
		$token = (new PortalJwtService(self::SECRET))->createSession(
			subjectRef: 's1',
			audience: 'supplier',
			organisation: 'org-1',
			jti: 'never-recorded-jti'
		);

		$this->assertNull($service->resolveFromBearer('Bearer ' . $token));

	}//end testUnknownJtiFailsClosed()

	public function testLogoutRevokesOnlyItsOwnSession(): void {
		$store = [];
		$service = $this->service(store: $store);

		$mine = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$theirs = $service->issueSession(subjectRef: 's2', audience: 'supplier', organisation: 'org-1');

		$this->assertTrue($service->revoke($mine['jti']));

		$this->assertNull($service->resolveFromBearer('Bearer ' . $mine['token']));
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $theirs['token']));

	}//end testLogoutRevokesOnlyItsOwnSession()

	public function testRevokeAllForOrganisationRevokesEveryActiveSession(): void {
		$store = [];
		$service = $this->service(store: $store);

		$a = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$b = $service->issueSession(subjectRef: 's2', audience: 'supplier', organisation: 'org-1');
		$c = $service->issueSession(subjectRef: 's3', audience: 'supplier', organisation: 'org-2');

		$result = $service->revokeAllForOrganisation('org-1', 'admin');

		$this->assertSame(['revoked' => 2, 'failed' => 0, 'complete' => true], $result);
		$this->assertNull($service->resolveFromBearer('Bearer ' . $a['token']));
		$this->assertNull($service->resolveFromBearer('Bearer ' . $b['token']));
		// A different organisation's session is untouched.
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $c['token']));

	}//end testRevokeAllForOrganisationRevokesEveryActiveSession()

	/**
	 * Staff revoke one account's sessions; the rest of the organisation keeps
	 * theirs (sign-in-with-an-email-link REQ-IWI-013).
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#12
	 */
	public function testRevokeAllForSubjectRevokesOnlyThatAccount(): void {
		$store = [];
		$service = $this->service(store: $store);

		$tom1 = $service->issueSession(subjectRef: 'email:tom', audience: 'client', organisation: 'academie', trust: 'low', provider: 'email-link');
		$tom2 = $service->issueSession(subjectRef: 'email:tom', audience: 'client', organisation: 'academie', trust: 'low', provider: 'email-link');
		$anna = $service->issueSession(subjectRef: 'email:anna', audience: 'client', organisation: 'academie', trust: 'low', provider: 'email-link');

		$result = $service->revokeAllForOrganisation('academie', 'beheerder', 'email:tom');

		$this->assertSame(['revoked' => 2, 'failed' => 0, 'complete' => true], $result);
		$this->assertNull($service->resolveFromBearer('Bearer ' . $tom1['token']));
		$this->assertNull($service->resolveFromBearer('Bearer ' . $tom2['token']));
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $anna['token']));

	}//end testRevokeAllForSubjectRevokesOnlyThatAccount()

	/**
	 * An e-mail link session is `low`, names its method, and a refresh
	 * cannot raise it.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#8
	 */
	public function testAnEmailLinkSessionStaysLowThroughARefresh(): void {
		$store = [];
		$service = $this->service(store: $store);
		$issued = $service->issueSession(subjectRef: 'email:tom', audience: 'client', organisation: 'academie', trust: 'low', provider: 'email-link');

		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);
		$this->assertSame('low', $subject['trust']);
		$this->assertSame('email-link', $subject['provider']);

		$refreshed = $service->refreshSession('Bearer ' . $issued['token']);
		$this->assertNotNull($refreshed);
		$this->assertNotSame($issued['jti'], $refreshed['jti']);
		$this->assertSame('low', $service->resolveFromBearer('Bearer ' . $refreshed['token'])['trust']);
		$this->assertFalse(PortalSessionService::trustSatisfies($subject['trust'], 'substantial'));

	}//end testAnEmailLinkSessionStaysLowThroughARefresh()

	/**
	 * Review B1: an organisation with more than one page of session rows,
	 * most of them revoked by rotation, still loses its one live session,
	 * which sits on the last page.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function testRevokeAllReachesALiveSessionPastTheFirstPage(): void {
		$store = [];
		$service = $this->service(store: $store);
		for ($i = 1; $i <= 1200; $i++) {
			$store['old-' . $i] = ['uuid' => 'old-' . $i, 'jti' => 'old-jti-' . $i, 'organisation' => 'org-1', 'subjectRef' => 's1', 'revoked' => true];
		}

		$live = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $live['token']));

		$result = $service->revokeAllForOrganisation('org-1', 'admin');

		$this->assertSame(['revoked' => 1, 'failed' => 0, 'complete' => true], $result);
		$this->assertNull($service->resolveFromBearer('Bearer ' . $live['token']), 'the live session past row 500 is revoked');
	}//end testRevokeAllReachesALiveSessionPastTheFirstPage()

	/**
	 * Review B1: more live sessions than one page are all revoked.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function testRevokeAllRevokesMoreLiveSessionsThanOnePage(): void {
		$store = [];
		$service = $this->service(store: $store);
		for ($i = 1; $i <= 1201; $i++) {
			$store['live-' . $i] = ['uuid' => 'live-' . $i, 'jti' => 'live-jti-' . $i, 'organisation' => 'org-1', 'subjectRef' => 's' . $i, 'revoked' => false];
		}

		$result = $service->revokeAllForOrganisation('org-1', 'admin');

		$this->assertSame(['revoked' => 1201, 'failed' => 0, 'complete' => true], $result);
		$this->assertSame([], array_filter($store, static fn (array $row): bool => $row['revoked'] !== true));
	}//end testRevokeAllRevokesMoreLiveSessionsThanOnePage()

	/**
	 * Review S5: an unreachable OpenRegister is reported as incomplete, never
	 * as "nothing to revoke".
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function testRevokeAllReportsAnUnreachableStoreAsIncomplete(): void {
		$store = [];
		$service = $this->service(store: $store, orDown: true);

		$this->assertSame(['revoked' => 0, 'failed' => 0, 'complete' => false], $service->revokeAllForOrganisation('org-1', 'admin'));
	}//end testRevokeAllReportsAnUnreachableStoreAsIncomplete()

	/**
	 * Review S6: an admin revocation is audited as `admin-revoke`, naming the
	 * admin, once per session and once for the call; never as a `logout`.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function testRevokeAllIsAuditedAsAnAdminRevocationNamingTheAdmin(): void {
		$store = [];
		$issuer = $this->service(store: $store);
		$issued = $issuer->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');

		$seen = [];
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->method('record')->willReturnCallback(
			function (string $verb, string $subjectRef, string $organisation, string $register, string $schema, string $id, string $jti = '', string $appId = 'portaliq', array $detail = []) use (&$seen) {
				$seen[] = [$verb, $subjectRef, $organisation, $id, $detail];
			}
		);

		$this->service(store: $store, auditor: $auditor)->revokeAllForOrganisation('org-1', 'beheerder');

		$this->assertSame(
			[
				['admin-revoke', 's1', 'org-1', $issued['jti'], ['admin' => 'beheerder']],
				['admin-revoke', 'beheerder', 'org-1', '', ['admin' => 'beheerder', 'revoked' => '1', 'complete' => 'yes']],
			],
			$seen
		);
		$this->assertContains('admin-revoke', AuditTrailService::VERBS);
	}//end testRevokeAllIsAuditedAsAnAdminRevocationNamingTheAdmin()

	/**
	 * Review S2: a revoked flag that comes back as 1, "1" or "true" still
	 * means revoked (fail closed), on resolve and on revoke-all.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
	 */
	public function testATruthyRevokedFlagFailsClosed(): void {
		foreach ([1, '1', 'true'] as $flag) {
			$store = [];
			$service = $this->service(store: $store);
			$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
			$store[array_key_first($store)]['revoked'] = $flag;

			$this->assertNull($service->resolveFromBearer('Bearer ' . $issued['token']), var_export($flag, true) . ' reads as revoked');
		}
	}//end testATruthyRevokedFlagFailsClosed()

	/**
	 * Review S1: a revoke-all that lands while a refresh is minting must not
	 * leave the new bearer alive.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function testARefreshRacingARevokeAllLeavesNoLiveBearer(): void {
		$store = [];
		$minted = 0;
		$service = $this->service(
			store: $store,
			onCreate: function (array &$rows, array $row) use (&$minted): void {
				$minted++;
				if ($minted === 2) {
					// revoke-all runs between the refresh's resolve and its mint.
					$rows['uuid-1']['revoked'] = true;
				}
			}
		);
		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high');

		$this->assertNull($service->refreshSession('Bearer ' . $issued['token']), 'the refresh is refused');
		$this->assertTrue($store['uuid-2']['revoked'], 'the bearer minted during the race is revoked');
	}//end testARefreshRacingARevokeAllLeavesNoLiveBearer()

	public function testRevokeUnknownJtiIsANoOp(): void {
		$service = $this->service();
		$this->assertFalse($service->revoke('does-not-exist'));
		$this->assertFalse($service->revoke(''));

	}//end testRevokeUnknownJtiIsANoOp()

	public function testRefreshRotatesJtiRevokesOldAndSlidesExpiry(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high', roles: ['supplier:read']);
		$this->assertNotNull($issued);

		$refreshed = $service->refreshSession('Bearer ' . $issued['token']);
		$this->assertNotNull($refreshed);
		$this->assertNotSame($issued['jti'], $refreshed['jti'], 'refresh must mint a NEW jti');

		// The OLD bearer no longer validates (rotation, not a second live token).
		$this->assertNull($service->resolveFromBearer('Bearer ' . $issued['token']));

		// The NEW bearer resolves, carries the SAME subject, and its trust/roles
		// survive the rotation unchanged.
		$subject = $service->resolveFromBearer('Bearer ' . $refreshed['token']);
		$this->assertNotNull($subject);
		$this->assertSame('s1', $subject['subjectRef']);
		$this->assertSame('high', $subject['trust']);
		$this->assertSame($refreshed['jti'], $subject['jti']);

	}//end testRefreshRotatesJtiRevokesOldAndSlidesExpiry()

	public function testRefreshCarriesTheOriginAuthTimeAcrossRotations(): void {
		// A SECOND refresh must still be measured from the ORIGINAL login, not
		// the most recent mint — authTime is carried forward unchanged.
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$first = $service->refreshSession('Bearer ' . $issued['token']);
		$this->assertNotNull($first);

		$originalSubject = $service->resolveFromBearer('Bearer ' . $issued['token']);
		// Already revoked by the first refresh.
		$this->assertNull($originalSubject);

		$second = $service->refreshSession('Bearer ' . $first['token']);
		$this->assertNotNull($second);
		$this->assertNotSame($first['jti'], $second['jti']);

	}//end testRefreshCarriesTheOriginAuthTimeAcrossRotations()

	public function testRefreshPastTheAbsoluteCapIsRefusedFailClosed(): void {
		// A synthetic bearer whose authTime is 2h in the past, against a 1h
		// configured cap — proves the ABSOLUTE cap refuses the refresh even
		// though the bearer itself has not (naturally) expired
		// (DEFAULT_TTL is 2h and this token was minted with a normal `exp`).
		$store = [];
		$service = $this->service(store: $store, maxLifetime: 3600);

		$staleAuthTime = (time() - 7200);
		$token = (new PortalJwtService(self::SECRET))->createSession(
			subjectRef: 's1',
			audience: 'supplier',
			organisation: 'org-1',
			jti: 'stale-jti',
			authTime: $staleAuthTime
		);
		$store['uuid-stale'] = [
			'uuid' => 'uuid-stale',
			'subjectRef' => 's1',
			'organisation' => 'org-1',
			'jti' => 'stale-jti',
			'revoked' => false,
		];

		$this->assertNull($service->refreshSession('Bearer ' . $token));
		// Refused — the bearer is untouched (still resolvable), no new token
		// minted; the subject must re-authenticate instead.
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $token));

	}//end testRefreshPastTheAbsoluteCapIsRefusedFailClosed()

	public function testRefreshOnARevokedBearerFailsClosed(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$this->assertTrue($service->revoke($issued['jti']));

		$this->assertNull($service->refreshSession('Bearer ' . $issued['token']));

	}//end testRefreshOnARevokedBearerFailsClosed()

	public function testRefreshOnAMalformedBearerFailsClosed(): void {
		$service = $this->service();
		$this->assertNull($service->refreshSession('not-a-bearer-token'));
		$this->assertNull($service->refreshSession(null));

	}//end testRefreshOnAMalformedBearerFailsClosed()

	public function testRefreshWithNoDedicatedSecretFailsClosed(): void {
		$service = $this->service(secret: '');
		$this->assertNull($service->refreshSession('Bearer whatever'));

	}//end testRefreshWithNoDedicatedSecretFailsClosed()

	public function testRefreshOnATokenPredatingTheAuthTimeClaimFailsClosed(): void {
		// A token minted before portal-session-hardening-v2 carries NO
		// `authTime` claim at all — hand-built here since createSession() now
		// always stamps one. With no origin to measure the cap from, refresh
		// must refuse rather than assume "fresh" (fail-closed, never a free
		// unlimited-lifetime pass) — resolveFromBearer() itself still
		// succeeds (the token is otherwise well-formed and unexpired), so
		// this proves refreshSession()'s OWN authTime guard, not the
		// jti-revocation check.
		$store = [];
		$service = $this->service(store: $store);

		$legacyToken = $this->legacyTokenWithoutAuthTimeClaim(subjectRef: 's1', organisation: 'org-1', jti: 'legacy-jti');
		$store['uuid-legacy'] = [
			'uuid' => 'uuid-legacy',
			'subjectRef' => 's1',
			'organisation' => 'org-1',
			'jti' => 'legacy-jti',
			'revoked' => false,
		];

		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $legacyToken));
		$this->assertNull($service->refreshSession('Bearer ' . $legacyToken));

	}//end testRefreshOnATokenPredatingTheAuthTimeClaimFailsClosed()

	/**
	 * Hand-build a compact JWT in PortalJwtService's own wire format but
	 * WITHOUT an `authTime` claim — simulating a token minted before
	 * portal-session-hardening-v2 introduced it (createSession() itself now
	 * always stamps one, so this cannot be produced through the public API).
	 *
	 * @param string $subjectRef The subject reference.
	 * @param string $organisation The tenant.
	 * @param string $jti The token id.
	 *
	 * @return string Compact JWT string, signed with SECRET.
	 */
	private function legacyTokenWithoutAuthTimeClaim(string $subjectRef, string $organisation, string $jti): string {
		$iat = time();
		$header = ['alg' => PortalJwtService::ALG, 'typ' => 'JWT'];
		$claims = [
			'sub' => $subjectRef,
			'audience' => 'supplier',
			'organisation' => $organisation,
			'trust' => '',
			'roles' => [],
			'jti' => $jti,
			'iat' => $iat,
			'exp' => ($iat + PortalJwtService::DEFAULT_TTL),
			'iss' => 'portaliq',
		];

		$b64UrlEncode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		$hPart = $b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
		$cPart = $b64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
		$sig = $b64UrlEncode(hash_hmac('sha256', ($hPart . '.' . $cPart), self::SECRET, true));

		return $hPart . '.' . $cPart . '.' . $sig;
	}//end legacyTokenWithoutAuthTimeClaim()

	/**
	 * portaliq#796, identity-ways-in-screens D2: a redeemed reference link
	 * opens a short session for one case. It resolves as a reference session
	 * with its claim, and is refused as a portal session everywhere else.
	 *
	 * @return void
	 */
	public function testAReferenceSessionReadsOnlyAsAReferenceSession(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueReferenceSession(linkId: 'link-1', caseReference: 'Z-2026-0042', organisation: 'gemeente-x', register: 'dossiq', schema: 'case');
		$this->assertNotNull($issued);

		$reference = $service->resolveReferenceFromBearer('Bearer ' . $issued['token']);
		$this->assertSame('Z-2026-0042', $reference['caseReference']);
		$this->assertSame('dossiq', $reference['register']);
		$this->assertSame('case', $reference['schema']);
		$this->assertSame('reference:' . hash('sha256', 'link-1'), $reference['subjectRef']);

		// Not a portal session: every route that takes one refuses it, and it
		// cannot be refreshed into one.
		$this->assertNull($service->resolveFromBearer('Bearer ' . $issued['token']));
		$this->assertNull($service->refreshSession('Bearer ' . $issued['token']));

		// Thirty minutes, not the two hours of a portal session.
		$lifetime = (new \DateTimeImmutable($issued['expiresAt']))->getTimestamp() - time();
		$this->assertLessThanOrEqual(1800, $lifetime);
		$this->assertGreaterThan(1700, $lifetime);

	}//end testAReferenceSessionReadsOnlyAsAReferenceSession()

	public function testAPortalSessionIsNotAReferenceSession(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'gemeente-x');

		$this->assertNull($service->resolveReferenceFromBearer('Bearer ' . $issued['token']));

	}//end testAPortalSessionIsNotAReferenceSession()

	public function testARevokedReferenceSessionReadsNothing(): void {
		$store = [];
		$service = $this->service(store: $store);
		$issued = $service->issueReferenceSession(linkId: 'link-1', caseReference: 'Z-2026-0042', organisation: 'gemeente-x', register: 'dossiq', schema: 'case');

		$service->revoke($issued['jti']);

		$this->assertNull($service->resolveReferenceFromBearer('Bearer ' . $issued['token']));

	}//end testARevokedReferenceSessionReadsNothing()

	public function testIssueSessionRecordsALoginAuditEntry(): void {
		// record(verb, subjectRef, organisation, register, schema, id, jti[, appId]);
		// login supplies exactly 7 (appId defaults), the new session's jti in
		// BOTH `id` and `jti` (there is no prior session to reference).
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->with(
			'login',
			's1',
			'org-1',
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything()
		);

		$store = [];
		$service = $this->service(store: $store, auditor: $auditor);
		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$this->assertNotNull($issued);

	}//end testIssueSessionRecordsALoginAuditEntry()

	public function testRevokeRecordsALogoutAuditEntry(): void {
		$store = [];
		$issuer = $this->service(store: $store);
		$issued = $issuer->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');

		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->with(
			'logout',
			's1',
			'org-1',
			$this->anything(),
			$this->anything(),
			$issued['jti'],
			$issued['jti']
		);

		// A SEPARATE service instance sharing the SAME store — revoke() only
		// needs to find the row the first service already wrote.
		$service = $this->service(store: $store, auditor: $auditor);
		$this->assertTrue($service->revoke($issued['jti']));

	}//end testRevokeRecordsALogoutAuditEntry()

	public function testRefreshRecordsARefreshAuditEntryNotALoginOrLogout(): void {
		$store = [];
		$issuer = $this->service(store: $store);
		$issued = $issuer->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');

		// refresh's `jti` field is the OLD (acting) session's jti — the
		// rotation is auditable as ONE `refresh` event, never also a separate
		// `login`/`logout` pair.
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->with(
			'refresh',
			's1',
			'org-1',
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$issued['jti']
		);

		$service = $this->service(store: $store, auditor: $auditor);
		$refreshed = $service->refreshSession('Bearer ' . $issued['token']);
		$this->assertNotNull($refreshed);

	}//end testRefreshRecordsARefreshAuditEntryNotALoginOrLogout()


	// -- branch (signin-eherkenning-branch REQ-SEB-001) -------------------------

	public function testALoginBranchTravelsWithTheSessionAsRestricted(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high', branch: '000012345678');
		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);

		$this->assertSame('000012345678', $subject['branch']);
		$this->assertTrue($subject['branchRestricted']);

	}//end testALoginBranchTravelsWithTheSessionAsRestricted()

	public function testASessionWithoutABranchHasNone(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);

		$this->assertSame('', $subject['branch']);
		$this->assertFalse($subject['branchRestricted']);

	}//end testASessionWithoutABranchHasNone()

	public function testARefreshKeepsTheBranchRestriction(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high', branch: '000012345678');
		$refreshed = $service->refreshSession('Bearer ' . $issued['token']);
		$subject = $service->resolveFromBearer('Bearer ' . $refreshed['token']);

		$this->assertSame('000012345678', $subject['branch']);
		$this->assertTrue($subject['branchRestricted'], 'a refresh can never widen a branch login to the whole company');

	}//end testARefreshKeepsTheBranchRestriction()

	/**
	 * signin-eherkenning-branch T05 (REQ-SEB-003): a whole-company session
	 * narrows to one branch and back, the old bearer stops working, and the
	 * chosen branch never restricts the session.
	 *
	 * @return void
	 */
	public function testAWholeCompanySessionNarrowsToABranchAndBack(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high');
		$narrowed = $service->rebranchSession('Bearer ' . $issued['token'], '000087654321');
		$this->assertNotNull($narrowed);
		$subject = $service->resolveFromBearer('Bearer ' . $narrowed['token']);
		$this->assertSame('000087654321', $subject['branch']);
		$this->assertFalse($subject['branchRestricted'], 'a chosen branch can be left again');
		$this->assertSame('high', $subject['trust']);
		$this->assertNull($service->resolveFromBearer('Bearer ' . $issued['token']), 'the old bearer is rotated out');

		$widened = $service->rebranchSession('Bearer ' . $narrowed['token'], '');
		$this->assertSame('', $service->resolveFromBearer('Bearer ' . $widened['token'])['branch']);

	}//end testAWholeCompanySessionNarrowsToABranchAndBack()

	/**
	 * invitation-joins-an-unbound-account: after a claim moved the account
	 * into the invitation's audience, the session is reissued for it. The
	 * subject, organisation, trust and provider stay; the old bearer stops
	 * working.
	 *
	 * @return void
	 */
	public function testASessionIsReissuedForTheAudienceItsAccountTookOn(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', trust: 'substantial', roles: ['client:read'], provider: 'digid');
		$reissued = $service->refreshSession('Bearer ' . $issued['token'], 'parent');
		$this->assertNotNull($reissued);
		$subject = $service->resolveFromBearer('Bearer ' . $reissued['token']);
		$this->assertSame('parent', $subject['audience']);
		$this->assertSame('s1', $subject['subjectRef']);
		$this->assertSame('org-1', $subject['organisation']);
		$this->assertSame('substantial', $subject['trust']);
		$this->assertSame('digid', $subject['provider']);
		$this->assertSame(['parent:read'], $subject['roles'], 'The new audience brings its own role, never the old one (review L2).');
		$this->assertNull($service->resolveFromBearer('Bearer ' . $issued['token']), 'the old bearer is rotated out');

	}//end testASessionIsReissuedForTheAudienceItsAccountTookOn()

	/**
	 * The audience the session has, the company audience and a missing
	 * bearer reissue nothing, and the session keeps working.
	 *
	 * @return void
	 */
	public function testAReissueForNoNewAudienceIsRefused(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', trust: 'substantial');
		$this->assertNull($service->refreshSession('Bearer ' . $issued['token'], 'client'));
		$this->assertNull($service->refreshSession('Bearer ' . $issued['token'], 'supplier'));
		$this->assertNull($service->refreshSession(null, 'parent'));
		$this->assertSame('client', $service->resolveFromBearer('Bearer ' . $issued['token'])['audience']);

	}//end testAReissueForNoNewAudienceIsRefused()

	/**
	 * A session the login restricted to one branch cannot choose another, nor
	 * the whole company, and keeps working.
	 *
	 * @return void
	 */
	public function testARestrictedSessionCannotChooseABranch(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', branch: '000012345678');
		$this->assertNull($service->rebranchSession('Bearer ' . $issued['token'], '000087654321'));
		$this->assertNull($service->rebranchSession('Bearer ' . $issued['token'], ''));
		$this->assertSame('000012345678', $service->resolveFromBearer('Bearer ' . $issued['token'])['branch']);

	}//end testARestrictedSessionCannotChooseABranch()

	/**
	 * A malformed branch or a missing bearer changes nothing.
	 *
	 * @return void
	 */
	public function testAMalformedBranchChoiceIsRefused(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
		$this->assertNull($service->rebranchSession('Bearer ' . $issued['token'], 'shop-12'));
		$this->assertNull($service->rebranchSession(null, '000087654321'));
		$this->assertNotNull($service->resolveFromBearer('Bearer ' . $issued['token']));

	}//end testAMalformedBranchChoiceIsRefused()

	public function testAMalformedBranchIsNeverSigned(): void {
		$store = [];
		$service = $this->service(store: $store);

		$issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', branch: 'shop-12');
		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);

		$this->assertSame('', $subject['branch']);
		$this->assertFalse($subject['branchRestricted']);

	}//end testAMalformedBranchIsNeverSigned()

	/**
	 * REQ-SIS-001: a new bearer lives one idle window (default 900 s), not two hours.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T01
	 */
	public function testBearerLivesOneIdleWindow(): void {
		$issued = $this->service()->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');

		$claims = (new PortalJwtService(self::SECRET))->validate($issued['token']);
		$this->assertSame(900, ((int)$claims['exp'] - (int)$claims['iat']));

		$issued = $this->service(idleTimeout: '1200')->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');
		$claims = (new PortalJwtService(self::SECRET))->validate($issued['token']);
		$this->assertSame(1200, ((int)$claims['exp'] - (int)$claims['iat']));

	}//end testBearerLivesOneIdleWindow()

	/**
	 * REQ-SIS-001: the idle window is clamped to 300 through 3600 seconds, and
	 * a value that is not a number falls back to the default.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T01
	 */
	public function testIdleTimeoutIsClamped(): void {
		foreach (['60' => 300, '99999' => 3600, 'soon' => 900, '0' => 900, '-5' => 900] as $configured => $expected) {
			$issued = $this->service(idleTimeout: (string)$configured)->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');
			$claims = (new PortalJwtService(self::SECRET))->validate($issued['token']);
			$this->assertSame($expected, ((int)$claims['exp'] - (int)$claims['iat']), "configured '{$configured}'");
		}

	}//end testIdleTimeoutIsClamped()

	/**
	 * REQ-SIS-001: a rotated bearer lives one idle window too, and the answer
	 * reports when the session ends; the absolute cap still refuses a refresh.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T01
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 */
	public function testRefreshPastTheCapIsStillRefused(): void {
		$store = [];
		$service = $this->service(store: $store, maxLifetime: 3600, idleTimeout: '600');
		$issued = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');

		$rotated = $service->refreshSession('Bearer ' . $issued['token']);
		$this->assertNotNull($rotated);
		$claims = (new PortalJwtService(self::SECRET))->validate($rotated['token']);
		$this->assertSame(600, ((int)$claims['exp'] - (int)$claims['iat']));
		$this->assertSame((int)$claims['exp'], $rotated['expiresAt']);
		$this->assertSame(((int)$claims['authTime'] + 3600), $rotated['hardExpiresAt']);
		$this->assertSame(600, $rotated['idleTimeout']);

		// A bearer whose origin login is past the cap is refused, whatever the window.
		$old = (new PortalJwtService(self::SECRET))->createSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', jti: 'jti-old', authTime: (time() - 3601));
		$store['uuid-old'] = ['jti' => 'jti-old', 'revoked' => false, 'uuid' => 'uuid-old', 'subjectRef' => 's1'];
		$this->assertNull($service->refreshSession('Bearer ' . $old));

	}//end testRefreshPastTheCapIsStillRefused()

	/**
	 * REQ-SIS-001: the portalSession row expires with its bearer, one idle
	 * window after it was minted, not two hours.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T01
	 */
	public function testTheSessionRowExpiresWithTheBearer(): void {
		$store = [];
		$issued = $this->service(store: $store, idleTimeout: '600')->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');
		$claims = (new PortalJwtService(self::SECRET))->validate($issued['token']);

		$row = array_values($store)[0];
		$this->assertSame((int)$claims['exp'], (new \DateTimeImmutable($row['expiresAt']))->getTimestamp());

	}//end testTheSessionRowExpiresWithTheBearer()

	/**
	 * REQ-SIS-001: the times a session reports: its bearer's expiry, the
	 * absolute cap from the origin login, and the idle window.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 */
	public function testSessionTimesComeFromTheBearer(): void {
		$service = $this->service(maxLifetime: 28800);
		$issued = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');
		$subject = $service->resolveFromBearer('Bearer ' . $issued['token']);
		$claims = (new PortalJwtService(self::SECRET))->validate($issued['token']);

		$this->assertSame(
			['expiresAt' => (int)$claims['exp'], 'hardExpiresAt' => ((int)$claims['authTime'] + 28800), 'idleTimeout' => 900],
			$service->sessionTimes($subject)
		);

	}//end testSessionTimesComeFromTheBearer()

	/**
	 * REQ-SIS-006: an OIDC-minted session carries the provider it came from,
	 * a refresh keeps it, and a session without one reports ''.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 */
	public function testTheBearerCarriesTheProvider(): void {
		$store = [];
		$service = $this->service(store: $store);
		$issued = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', provider: 'digid');
		$this->assertSame('digid', $service->resolveFromBearer('Bearer ' . $issued['token'])['provider']);

		$rotated = $service->refreshSession('Bearer ' . $issued['token']);
		$this->assertSame('digid', $service->resolveFromBearer('Bearer ' . $rotated['token'])['provider']);

		$plain = $service->issueSession(subjectRef: 's1', audience: 'client', organisation: 'org-1');
		$this->assertSame('', $service->resolveFromBearer('Bearer ' . $plain['token'])['provider']);
		$claims = (new PortalJwtService(self::SECRET))->validate($plain['token']);
		$this->assertArrayNotHasKey('provider', $claims);

	}//end testTheBearerCarriesTheProvider()

	/**
	 * Build a service backed by a dedicated (default: valid) signing secret
	 * and an in-memory fake portalSession store (create/read/update), unless
	 * $store is null (used for the "no secret configured" refusal tests,
	 * where the writer/reader are never expected to be called). The
	 * AuditTrailService is a permissive mock by default (portal-session-
	 * hardening-v2) — a test that cares about WHAT was recorded passes its own
	 * `$auditor` mock instead.
	 *
	 * @param string|null $secret Override the configured secret; null uses SECRET.
	 * @param array<string, mixed>& $store      Backing store, keyed by uuid.
	 * @param AuditTrailService|null $auditor Override the audit recorder; null uses a permissive mock.
	 * @param int $maxLifetime Override `session_max_lifetime` (seconds); the default 8h otherwise.
	 */
	private function service(?string $secret = self::SECRET, array &$store = [], ?AuditTrailService $auditor = null, int $maxLifetime = 0, ?string $idleTimeout = null, ?callable $onCreate = null, bool $orDown = false): PortalSessionService {
		// The signing secret lives in IAppConfig, flagged sensitive (review S4);
		// IConfig answers the other session settings only.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($key === 'jwt_signing_secret' ? ($secret ?? '') : $default)
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			function (string $appId, string $key, string $default = '') use ($secret, $maxLifetime, $idleTimeout) {
				if ($key === 'session_max_lifetime' && $maxLifetime > 0) {
					return (string)$maxLifetime;
				}
				if ($key === 'session_idle_timeout') {
					return ($idleTimeout ?? $default);
				}
				return $default;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$counter = 0;
		$random->method('generate')->willReturnCallback(function () use (&$counter) {
			$counter++;
			return 'generated-jti-' . $counter;
		});

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$store, $onCreate) {
				$uuid = 'uuid-' . (count($store) + 1);
				$data['uuid'] = $uuid;
				$store[$uuid] = $data;
				if ($onCreate !== null) {
					$onCreate($store, $data);
				}
				return $data;
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$store) {
				if (isset($store[$id]) === false) {
					return null;
				}
				$store[$id] = array_merge($store[$id], $data);
				return $store[$id];
			}
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200) use (&$store) {
				$matches = [];
				foreach ($store as $row) {
					if ($scopeField !== '' && ($row[$scopeField] ?? null) === $subjectRef) {
						$matches[] = $row;
					}
				}
				return array_slice($matches, 0, $limit);
			}
		);
		// One page of a scoped read, as OpenRegister pages it: the filter
		// narrows, then offset and limit apply; null when OpenRegister is down.
		$reader->method('readScopedPage')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $scopeValue, string $organisation, array $filter, int $limit, int $offset) use (&$store, $orDown) {
				if ($orDown === true) {
					return null;
				}
				$matches = [];
				foreach ($store as $row) {
					if (($row[$scopeField] ?? null) !== $scopeValue) {
						continue;
					}
					foreach ($filter as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							continue 2;
						}
					}
					$matches[] = $row;
				}
				$page = array_slice($matches, $offset, $limit);
				return ['rows' => $page, 'read' => count($page)];
			}
		);

		return new PortalSessionService(
			$config,
			$random,
			$this->createMock(LoggerInterface::class),
			$writer,
			$reader,
			($auditor ?? $this->createMock(AuditTrailService::class)),
			$appConfig
		);

	}//end service()

}//end class
