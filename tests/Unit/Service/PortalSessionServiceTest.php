<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalJwtService;
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
 * @spec openspec/changes/contract-v2/tasks.md#T1
 * @spec openspec/changes/contract-v2/tasks.md#T7
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

    private function service(): PortalSessionService
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $config->method('getSystemValue')->willReturn(self::SECRET);

        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturn('generated-jti-000');

        return new PortalSessionService($config, $random, $this->createMock(LoggerInterface::class));

    }//end service()

}//end class
