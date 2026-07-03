<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Portaliq\Service\PortalJwtService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the portal auth-edge JWT service. Proves the token round-trips
 * and, critically, that forged / tampered / expired tokens FAIL CLOSED.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalJwtServiceTest extends TestCase
{

    private const SECRET = 'unit-test-signing-secret-000000000';

    public function testRoundTripReturnsClaims(): void
    {
        $jwt   = new PortalJwtService(self::SECRET);
        $token = $jwt->createSession(
            subjectRef: 'supplier-1',
            audience: 'supplier',
            organisation: 'gemeente-x',
            jti: 'jti-1',
            trust: 'EH3',
            roles: ['supplier:read']
        );

        $claims = $jwt->validate($token);
        $this->assertSame('supplier-1', $claims['sub']);
        $this->assertSame('supplier', $claims['audience']);
        $this->assertSame('gemeente-x', $claims['organisation']);
        $this->assertSame('jti-1', $claims['jti']);
        $this->assertSame('EH3', $claims['trust']);
        $this->assertSame('portaliq', $claims['iss']);
        $this->assertContains('supplier:read', $claims['roles']);

    }//end testRoundTripReturnsClaims()

    public function testTamperedPayloadIsRejected(): void
    {
        $jwt   = new PortalJwtService(self::SECRET);
        $token = $jwt->createSession(
            subjectRef: 'supplier-1',
            audience: 'supplier',
            organisation: 'gemeente-x',
            jti: 'jti-1'
        );

        // Flip the middle (claims) segment.
        [$h, $c, $s] = explode('.', $token);
        $forged      = $h.'.'.$c.'x.'.$s;

        $this->expectException(RuntimeException::class);
        $jwt->validate($forged);

    }//end testTamperedPayloadIsRejected()

    public function testForeignSecretIsRejected(): void
    {
        $minter   = new PortalJwtService(self::SECRET);
        $verifier = new PortalJwtService('a-completely-different-secret-000');
        $token    = $minter->createSession(
            subjectRef: 'supplier-1',
            audience: 'supplier',
            organisation: 'gemeente-x',
            jti: 'jti-1'
        );

        $this->expectException(RuntimeException::class);
        $verifier->validate($token);

    }//end testForeignSecretIsRejected()

    public function testExpiredTokenIsRejected(): void
    {
        $jwt   = new PortalJwtService(self::SECRET);
        $token = $jwt->createSession(
            subjectRef: 'supplier-1',
            audience: 'supplier',
            organisation: 'gemeente-x',
            jti: 'jti-1',
            trust: '',
            roles: [],
            ttl: -10
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expired');
        $jwt->validate($token);

    }//end testExpiredTokenIsRejected()

    public function testMalformedTokenIsRejected(): void
    {
        $jwt = new PortalJwtService(self::SECRET);
        $this->expectException(RuntimeException::class);
        $jwt->validate('not-a-jwt');

    }//end testMalformedTokenIsRejected()

    public function testShortSecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PortalJwtService('too-short');

    }//end testShortSecretIsRejected()

}//end class
