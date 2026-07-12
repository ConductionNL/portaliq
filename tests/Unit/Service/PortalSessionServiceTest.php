<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

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
 * @spec openspec/changes/contract-v2/tasks.md#T1
 * @spec openspec/changes/contract-v2/tasks.md#T7
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.1
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.3
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.1
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.2
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#2.3
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.1
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

    /**
     * Build a service backed by a dedicated (default: valid) signing secret
     * and an in-memory fake portalSession store (create/read/update), unless
     * $store is null (used for the "no secret configured" refusal tests,
     * where the writer/reader are never expected to be called).
     *
     * @param string|null           $secret Override the configured secret; null uses SECRET.
     * @param array<string, mixed>& $store  Backing store, keyed by uuid.
     */
    private function service(?string $secret=self::SECRET, array &$store=[]): PortalSessionService
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn($secret ?? '');

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
            function (string $register, string $schema, string $uuid, array $data) use (&$store) {
                if (isset($store[$uuid]) === false) {
                    return null;
                }
                $store[$uuid] = array_merge($store[$uuid], $data);
                return $store[$uuid];
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

        return new PortalSessionService($config, $random, $this->createMock(LoggerInterface::class), $writer, $reader);

    }//end service()

}//end class
