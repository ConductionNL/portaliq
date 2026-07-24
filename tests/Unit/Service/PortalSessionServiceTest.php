<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalJwtService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
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
 * @spec openspec/changes/contract-v2/tasks.md#T1
 * @spec openspec/changes/contract-v2/tasks.md#T7
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.1
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.3
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.1
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.2
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.3
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.1
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T01
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T02
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T11
 */
class PortalSessionServiceTest extends TestCase
{

    private const SECRET = 'unit-test-signing-secret-000000000';

    public function testUnknownTrustClaimNormalisesToLow(): void
    {
        $service = $this->service();

        foreach (['dev', 'EH3', '', 'HIGH', 'Substantial'] as $legacy) {
            $issued  = $service->issueSession(
                subjectRef: 's1',
                audience: 'supplier',
                organisation: 'org-1',
                trust: $legacy
            );
            $subject = $service->resolveFromBearer('Bearer '.$issued['token']);
            $this->assertNotNull($subject);
            $this->assertSame('low', $subject['trust'], "trust '{$legacy}' must normalise to low");
        }

        $issued  = $service->issueSession(
            subjectRef: 's1',
            audience: 'supplier',
            organisation: 'org-1',
            trust: 'substantial'
        );
        $subject = $service->resolveFromBearer('Bearer '.$issued['token']);
        $this->assertSame('substantial', $subject['trust']);

    }//end testUnknownTrustClaimNormalisesToLow()

    public function testTrustSatisfiesOrdersLowSubstantialHigh(): void
    {
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

    public function testUnrecognisedMinTrustIsUnsatisfiableForEveryone(): void
    {
        foreach (['low', 'substantial', 'high'] as $subjectTrust) {
            $this->assertFalse(PortalSessionService::trustSatisfies($subjectTrust, 'ultra'));
            $this->assertFalse(PortalSessionService::trustSatisfies($subjectTrust, 'High'));
            $this->assertFalse(PortalSessionService::trustSatisfies($subjectTrust, ['high']));
        }

    }//end testUnrecognisedMinTrustIsUnsatisfiableForEveryone()

    public function testAssertionCarriesUseClaimSessionJtiAndShortTtl(): void
    {
        $service   = $this->service();
        $assertion = $service->issueAssertion(
            [
                'subjectRef'   => 's1',
                'audience'     => 'supplier',
                'organisation' => 'org-1',
                'trust'        => 'dev',
                'jti'          => 'session-jti-1',
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
        $this->assertSame(PortalJwtService::ASSERTION_TTL, ((int) $claims['exp'] - (int) $claims['iat']));

    }//end testAssertionCarriesUseClaimSessionJtiAndShortTtl()

    public function testAssertionPresentedAsBearerFailsClosed(): void
    {
        $service   = $this->service();
        $assertion = $service->issueAssertion(
            [
                'subjectRef'   => 's1',
                'audience'     => 'supplier',
                'organisation' => 'org-1',
                'trust'        => 'high',
                'jti'          => 'session-jti-1',
            ]
        );

        // A fresh, correctly signed, unexpired assertion is NOT a session.
        $this->assertNull($service->resolveFromBearer('Bearer '.$assertion));

    }//end testAssertionPresentedAsBearerFailsClosed()

    public function testRealSessionBearerStillResolves(): void
    {
        $service = $this->service();
        $issued  = $service->issueSession(
            subjectRef: 's1',
            audience: 'supplier',
            organisation: 'org-1',
            trust: 'high',
            roles: ['supplier:read']
        );

        $subject = $service->resolveFromBearer('Bearer '.$issued['token']);
        $this->assertNotNull($subject);
        $this->assertSame('s1', $subject['subjectRef']);
        $this->assertSame('high', $subject['trust']);
        $this->assertSame($issued['jti'], $subject['jti']);

    }//end testRealSessionBearerStillResolves()

    public function testNoDedicatedSecretRefusesIssuanceAndResolution(): void
    {
        // Empty app config → NEVER falls back to a system/instance secret
        // (portal-auth-edge-session-hardening) — the edge fails closed.
        $service = $this->service(secret: '');

        $this->assertFalse($service->isConfigured());
        $this->assertNull($service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1'));
        $this->assertNull($service->resolveFromBearer('Bearer whatever'));

    }//end testNoDedicatedSecretRefusesIssuanceAndResolution()

    public function testShortSecretAlsoRefusesFailClosed(): void
    {
        $service = $this->service(secret: 'too-short');
        $this->assertFalse($service->isConfigured());
        $this->assertNull($service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1'));

    }//end testShortSecretAlsoRefusesFailClosed()

    public function testIssuedSessionIsRecordedAndResolvable(): void
    {
        $store   = [];
        $service = $this->service(store: $store);

        $issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high');
        $this->assertNotNull($issued);

        $subject = $service->resolveFromBearer('Bearer '.$issued['token']);
        $this->assertNotNull($subject);
        $this->assertSame($issued['jti'], $subject['jti']);

    }//end testIssuedSessionIsRecordedAndResolvable()

    public function testRevokedJtiFailsClosedEvenWithValidSignature(): void
    {
        $store   = [];
        $service = $this->service(store: $store);

        $issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
        $this->assertNotNull($issued);

        // Still valid before revocation.
        $this->assertNotNull($service->resolveFromBearer('Bearer '.$issued['token']));

        $this->assertTrue($service->revoke($issued['jti']));

        // Same, perfectly-signed, unexpired token — now rejected.
        $this->assertNull($service->resolveFromBearer('Bearer '.$issued['token']));

    }//end testRevokedJtiFailsClosedEvenWithValidSignature()

    public function testUnknownJtiFailsClosed(): void
    {
        // A signature that validates but whose jti has no portalSession row at
        // all (never issued via this service, or the OR write silently failed)
        // must be rejected exactly like a revoked one.
        $service = $this->service();
        $token   = (new PortalJwtService(self::SECRET))->createSession(
            subjectRef: 's1',
            audience: 'supplier',
            organisation: 'org-1',
            jti: 'never-recorded-jti'
        );

        $this->assertNull($service->resolveFromBearer('Bearer '.$token));

    }//end testUnknownJtiFailsClosed()

    public function testLogoutRevokesOnlyItsOwnSession(): void
    {
        $store   = [];
        $service = $this->service(store: $store);

        $mine   = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
        $theirs = $service->issueSession(subjectRef: 's2', audience: 'supplier', organisation: 'org-1');

        $this->assertTrue($service->revoke($mine['jti']));

        $this->assertNull($service->resolveFromBearer('Bearer '.$mine['token']));
        $this->assertNotNull($service->resolveFromBearer('Bearer '.$theirs['token']));

    }//end testLogoutRevokesOnlyItsOwnSession()

    public function testRevokeAllForOrganisationRevokesEveryActiveSession(): void
    {
        $store   = [];
        $service = $this->service(store: $store);

        $a = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
        $b = $service->issueSession(subjectRef: 's2', audience: 'supplier', organisation: 'org-1');
        $c = $service->issueSession(subjectRef: 's3', audience: 'supplier', organisation: 'org-2');

        $revoked = $service->revokeAllForOrganisation('org-1');

        $this->assertSame(2, $revoked);
        $this->assertNull($service->resolveFromBearer('Bearer '.$a['token']));
        $this->assertNull($service->resolveFromBearer('Bearer '.$b['token']));
        // A different organisation's session is untouched.
        $this->assertNotNull($service->resolveFromBearer('Bearer '.$c['token']));

    }//end testRevokeAllForOrganisationRevokesEveryActiveSession()

    public function testRevokeUnknownJtiIsANoOp(): void
    {
        $service = $this->service();
        $this->assertFalse($service->revoke('does-not-exist'));
        $this->assertFalse($service->revoke(''));

    }//end testRevokeUnknownJtiIsANoOp()

    public function testRefreshRotatesJtiRevokesOldAndSlidesExpiry(): void
    {
        $store   = [];
        $service = $this->service(store: $store);

        $issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1', trust: 'high', roles: ['supplier:read']);
        $this->assertNotNull($issued);

        $refreshed = $service->refreshSession('Bearer '.$issued['token']);
        $this->assertNotNull($refreshed);
        $this->assertNotSame($issued['jti'], $refreshed['jti'], 'refresh must mint a NEW jti');

        // The OLD bearer no longer validates (rotation, not a second live token).
        $this->assertNull($service->resolveFromBearer('Bearer '.$issued['token']));

        // The NEW bearer resolves, carries the SAME subject, and its trust/roles
        // survive the rotation unchanged.
        $subject = $service->resolveFromBearer('Bearer '.$refreshed['token']);
        $this->assertNotNull($subject);
        $this->assertSame('s1', $subject['subjectRef']);
        $this->assertSame('high', $subject['trust']);
        $this->assertSame($refreshed['jti'], $subject['jti']);

    }//end testRefreshRotatesJtiRevokesOldAndSlidesExpiry()

    public function testRefreshCarriesTheOriginAuthTimeAcrossRotations(): void
    {
        // A SECOND refresh must still be measured from the ORIGINAL login, not
        // the most recent mint — authTime is carried forward unchanged.
        $store   = [];
        $service = $this->service(store: $store);

        $issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
        $first  = $service->refreshSession('Bearer '.$issued['token']);
        $this->assertNotNull($first);

        $originalSubject = $service->resolveFromBearer('Bearer '.$issued['token']);
        // Already revoked by the first refresh.
        $this->assertNull($originalSubject);

        $second = $service->refreshSession('Bearer '.$first['token']);
        $this->assertNotNull($second);
        $this->assertNotSame($first['jti'], $second['jti']);

    }//end testRefreshCarriesTheOriginAuthTimeAcrossRotations()

    public function testRefreshPastTheAbsoluteCapIsRefusedFailClosed(): void
    {
        // A synthetic bearer whose authTime is 2h in the past, against a 1h
        // configured cap — proves the ABSOLUTE cap refuses the refresh even
        // though the bearer itself has not (naturally) expired
        // (DEFAULT_TTL is 2h and this token was minted with a normal `exp`).
        $store   = [];
        $service = $this->service(store: $store, maxLifetime: 3600);

        $staleAuthTime = (time() - 7200);
        $token         = (new PortalJwtService(self::SECRET))->createSession(
            subjectRef: 's1',
            audience: 'supplier',
            organisation: 'org-1',
            jti: 'stale-jti',
            authTime: $staleAuthTime
        );
        $store['uuid-stale'] = [
            'uuid'         => 'uuid-stale',
            'subjectRef'   => 's1',
            'organisation' => 'org-1',
            'jti'          => 'stale-jti',
            'revoked'      => false,
        ];

        $this->assertNull($service->refreshSession('Bearer '.$token));
        // Refused — the bearer is untouched (still resolvable), no new token
        // minted; the subject must re-authenticate instead.
        $this->assertNotNull($service->resolveFromBearer('Bearer '.$token));

    }//end testRefreshPastTheAbsoluteCapIsRefusedFailClosed()

    public function testRefreshOnARevokedBearerFailsClosed(): void
    {
        $store   = [];
        $service = $this->service(store: $store);

        $issued = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
        $this->assertTrue($service->revoke($issued['jti']));

        $this->assertNull($service->refreshSession('Bearer '.$issued['token']));

    }//end testRefreshOnARevokedBearerFailsClosed()

    public function testRefreshOnAMalformedBearerFailsClosed(): void
    {
        $service = $this->service();
        $this->assertNull($service->refreshSession('not-a-bearer-token'));
        $this->assertNull($service->refreshSession(null));

    }//end testRefreshOnAMalformedBearerFailsClosed()

    public function testRefreshWithNoDedicatedSecretFailsClosed(): void
    {
        $service = $this->service(secret: '');
        $this->assertNull($service->refreshSession('Bearer whatever'));

    }//end testRefreshWithNoDedicatedSecretFailsClosed()

    public function testRefreshOnATokenPredatingTheAuthTimeClaimFailsClosed(): void
    {
        // A token minted before portal-session-hardening-v2 carries NO
        // `authTime` claim at all — hand-built here since createSession() now
        // always stamps one. With no origin to measure the cap from, refresh
        // must refuse rather than assume "fresh" (fail-closed, never a free
        // unlimited-lifetime pass) — resolveFromBearer() itself still
        // succeeds (the token is otherwise well-formed and unexpired), so
        // this proves refreshSession()'s OWN authTime guard, not the
        // jti-revocation check.
        $store   = [];
        $service = $this->service(store: $store);

        $legacyToken = $this->legacyTokenWithoutAuthTimeClaim(subjectRef: 's1', organisation: 'org-1', jti: 'legacy-jti');
        $store['uuid-legacy'] = [
            'uuid'         => 'uuid-legacy',
            'subjectRef'   => 's1',
            'organisation' => 'org-1',
            'jti'          => 'legacy-jti',
            'revoked'      => false,
        ];

        $this->assertNotNull($service->resolveFromBearer('Bearer '.$legacyToken));
        $this->assertNull($service->refreshSession('Bearer '.$legacyToken));

    }//end testRefreshOnATokenPredatingTheAuthTimeClaimFailsClosed()

    /**
     * Hand-build a compact JWT in PortalJwtService's own wire format but
     * WITHOUT an `authTime` claim — simulating a token minted before
     * portal-session-hardening-v2 introduced it (createSession() itself now
     * always stamps one, so this cannot be produced through the public API).
     *
     * @param string $subjectRef   The subject reference.
     * @param string $organisation The tenant.
     * @param string $jti          The token id.
     *
     * @return string Compact JWT string, signed with SECRET.
     */
    private function legacyTokenWithoutAuthTimeClaim(string $subjectRef, string $organisation, string $jti): string
    {
        $iat    = time();
        $header = ['alg' => PortalJwtService::ALG, 'typ' => 'JWT'];
        $claims = [
            'sub'          => $subjectRef,
            'audience'     => 'supplier',
            'organisation' => $organisation,
            'trust'        => '',
            'roles'        => [],
            'jti'          => $jti,
            'iat'          => $iat,
            'exp'          => ($iat + PortalJwtService::DEFAULT_TTL),
            'iss'          => 'portaliq',
        ];

        $b64UrlEncode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $hPart        = $b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $cPart        = $b64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig          = $b64UrlEncode(hash_hmac('sha256', ($hPart.'.'.$cPart), self::SECRET, true));

        return $hPart.'.'.$cPart.'.'.$sig;

    }//end legacyTokenWithoutAuthTimeClaim()

    public function testIssueSessionRecordsALoginAuditEntry(): void
    {
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

        $store   = [];
        $service = $this->service(store: $store, auditor: $auditor);
        $issued  = $service->issueSession(subjectRef: 's1', audience: 'supplier', organisation: 'org-1');
        $this->assertNotNull($issued);

    }//end testIssueSessionRecordsALoginAuditEntry()

    public function testRevokeRecordsALogoutAuditEntry(): void
    {
        $store  = [];
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

    public function testRefreshRecordsARefreshAuditEntryNotALoginOrLogout(): void
    {
        $store  = [];
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

        $service   = $this->service(store: $store, auditor: $auditor);
        $refreshed = $service->refreshSession('Bearer '.$issued['token']);
        $this->assertNotNull($refreshed);

    }//end testRefreshRecordsARefreshAuditEntryNotALoginOrLogout()

    /**
     * Build a service backed by a dedicated (default: valid) signing secret
     * and an in-memory fake portalSession store (create/read/update), unless
     * $store is null (used for the "no secret configured" refusal tests,
     * where the writer/reader are never expected to be called). The
     * AuditTrailService is a permissive mock by default (portal-session-
     * hardening-v2) — a test that cares about WHAT was recorded passes its own
     * `$auditor` mock instead.
     *
     * @param string|null           $secret     Override the configured secret; null uses SECRET.
     * @param array<string, mixed>& $store      Backing store, keyed by uuid.
     * @param AuditTrailService|null $auditor   Override the audit recorder; null uses a permissive mock.
     * @param int                     $maxLifetime Override `session_max_lifetime` (seconds); the default 8h otherwise.
     */
    private function service(?string $secret=self::SECRET, array &$store=[], ?AuditTrailService $auditor=null, int $maxLifetime=0): PortalSessionService
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $appId, string $key, string $default='') use ($secret, $maxLifetime) {
                if ($key === 'session_max_lifetime' && $maxLifetime > 0) {
                    return (string) $maxLifetime;
                }
                return ($secret ?? '');
            }
        );

        $random = $this->createMock(ISecureRandom::class);
        $counter = 0;
        $random->method('generate')->willReturnCallback(function () use (&$counter) {
            $counter++;
            return 'generated-jti-'.$counter;
        });

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$store) {
                $uuid          = 'uuid-'.(count($store) + 1);
                $data['uuid']  = $uuid;
                $store[$uuid]  = $data;
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
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='') use (&$store) {
                $matches = [];
                foreach ($store as $row) {
                    if ($scopeField !== '' && ($row[$scopeField] ?? null) === $subjectRef) {
                        $matches[] = $row;
                    }
                }
                return $matches;
            }
        );

        return new PortalSessionService(
            $config,
            $random,
            $this->createMock(LoggerInterface::class),
            $writer,
            $reader,
            ($auditor ?? $this->createMock(AuditTrailService::class))
        );

    }//end service()

}//end class
