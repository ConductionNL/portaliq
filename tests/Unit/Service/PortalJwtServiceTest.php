<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Portaliq\Service\PortalJwtService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the portal auth-edge JWT service. Proves the token round-trips
 * and, critically, that forged / tampered / expired tokens FAIL CLOSED. Also
 * pins the A6 `X-Portal-Subject` assertion WIRE FORMAT (header + exact claim
 * set, as literals) so receiver-side verifiers templated in domain apps can
 * rely on a frozen shape — any drift fails this suite loudly.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 * @spec openspec/changes/field-projection/tasks.md#T4
 */
class PortalJwtServiceTest extends TestCase {

	private const SECRET = 'unit-test-signing-secret-000000000';

	public function testRoundTripReturnsClaims(): void {
		$jwt = new PortalJwtService(self::SECRET);
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

	public function testTamperedPayloadIsRejected(): void {
		$jwt = new PortalJwtService(self::SECRET);
		$token = $jwt->createSession(
			subjectRef: 'supplier-1',
			audience: 'supplier',
			organisation: 'gemeente-x',
			jti: 'jti-1'
		);

		// Flip the middle (claims) segment.
		[$h, $c, $s] = explode('.', $token);
		$forged = $h . '.' . $c . 'x.' . $s;

		$this->expectException(RuntimeException::class);
		$jwt->validate($forged);

	}//end testTamperedPayloadIsRejected()

	public function testForeignSecretIsRejected(): void {
		$minter = new PortalJwtService(self::SECRET);
		$verifier = new PortalJwtService('a-completely-different-secret-000');
		$token = $minter->createSession(
			subjectRef: 'supplier-1',
			audience: 'supplier',
			organisation: 'gemeente-x',
			jti: 'jti-1'
		);

		$this->expectException(RuntimeException::class);
		$verifier->validate($token);

	}//end testForeignSecretIsRejected()

	public function testExpiredTokenIsRejected(): void {
		$jwt = new PortalJwtService(self::SECRET);
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

	public function testMalformedTokenIsRejected(): void {
		$jwt = new PortalJwtService(self::SECRET);
		$this->expectException(RuntimeException::class);
		$jwt->validate('not-a-jwt');

	}//end testMalformedTokenIsRejected()

	public function testShortSecretIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		new PortalJwtService('too-short');

	}//end testShortSecretIsRejected()

	public function testAssertionWireFormatIsPinnedForReceiverVerifiers(): void {
		$jwt = new PortalJwtService(self::SECRET);
		$assertion = $jwt->createAssertion(
			subjectRef: 's1',
			audience: 'supplier',
			organisation: 'org-1',
			trust: 'low',
			jti: 'session-jti-1'
		);

		// COMPATIBILITY PIN — deliberately asserts LITERALS, not the class
		// constants: receiver-side X-Portal-Subject verifiers in domain apps
		// are templated against exactly this shape. If this test fails, the
		// assertion wire format changed and every receiver breaks — treat it
		// as a breaking contract change, never "fix" the literals casually.
		$parts = explode('.', $assertion);
		$this->assertCount(3, $parts);

		// Header: exactly HS256 / JWT, nothing else.
		$header = json_decode($this->b64UrlDecode($parts[0]), true);
		$this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);

		// Claims: the exact key set, in the exact serialisation order.
		$claims = json_decode($this->b64UrlDecode($parts[1]), true);
		$this->assertIsArray($claims);
		$this->assertSame(
			['sub', 'audience', 'organisation', 'trust', 'jti', 'use', 'iat', 'exp', 'iss'],
			array_keys($claims)
		);

		// Every claim value, explicitly.
		$this->assertSame('s1', $claims['sub']);
		$this->assertSame('supplier', $claims['audience']);
		$this->assertSame('org-1', $claims['organisation']);
		$this->assertSame('low', $claims['trust']);
		$this->assertSame('session-jti-1', $claims['jti']);
		$this->assertSame('assertion', $claims['use']);
		$this->assertIsInt($claims['iat']);
		$this->assertIsInt($claims['exp']);
		$this->assertSame(60, ($claims['exp'] - $claims['iat']));
		$this->assertSame('portaliq', $claims['iss']);

		// And the mint validates against its own signature (HS256 intact).
		$this->assertSame($claims, $jwt->validate($assertion));

	}//end testAssertionWireFormatIsPinnedForReceiverVerifiers()

	/**
	 * Base64-url decode (test-local twin of the service's private helper, so
	 * the pin decodes the wire bytes independently of the implementation).
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = (4 - (strlen($encoded) % 4));
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string)base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()

}//end class
