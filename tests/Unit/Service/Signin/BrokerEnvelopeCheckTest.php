<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Service\Signin
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Signin;

use OCA\Portaliq\Service\Signin\BrokerEnvelopeCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T06: every claim portaliq acts on is checked; each mutation reddens one
 * case. The claims are integriq's own shape (SubjectEnvelope::toClaims()
 * plus the jti, iat and exp SubjectEnvelopeService::sign() adds, integriq
 * development 21e747d).
 *
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-every-claim-portaliq-acts-on-is-checked-req-bel-004
 */
class BrokerEnvelopeCheckTest extends TestCase {

	private const NOW = 1790000000;


	/**
	 * Integriq's envelope claims for a DigiD login at gemeente-x.
	 *
	 * @return array<string, mixed>
	 */
	public static function claims(): array {
		return [
			'sub' => 'pseudonym-3f2a',
			'subType' => 'bsn-pseudonym',
			'provider' => 'digid',
			'audience' => 'portaliq-venray',
			'organisation' => 'gemeente-x',
			'trust' => 'substantial',
			'use' => 'idp-envelope',
			'iss' => 'openconnector-idp-broker',
			'jti' => 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4',
			'iat' => (self::NOW - 10),
			'exp' => (self::NOW + 50),
		];
	}//end claims()


	/**
	 * A compact JWS over the claims. The signature is not checked by portaliq
	 * (design D5), so any bytes stand in for it.
	 *
	 * @param array<string, mixed> $claims The claims.
	 *
	 * @return string
	 */
	public static function envelope(array $claims): string {
		$encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

		return $encode('{"alg":"HS256","typ":"JWT"}') . '.' . $encode((string)json_encode($claims)) . '.' . $encode(random_bytes(32));
	}//end envelope()


	/**
	 * The check as the login runs it.
	 *
	 * @param string $envelope The envelope.
	 *
	 * @return array<string, string>|null
	 */
	private function check(string $envelope): ?array {
		return (new BrokerEnvelopeCheck())->check(envelope: $envelope, consumerId: 'portaliq-venray', org: 'gemeente-x', provider: 'digid', now: self::NOW);
	}//end check()


	/**
	 * The positive control: without it every refusal below passes against a
	 * check that refuses everything.
	 *
	 * @return void
	 */
	public function testAnEnvelopeForThisLoginIsAccepted(): void {
		$this->assertSame(['sub' => 'pseudonym-3f2a', 'provider' => 'digid', 'trust' => 'substantial'], $this->check(self::envelope(self::claims())));
	}//end testAnEnvelopeForThisLoginIsAccepted()


	/**
	 * One mutation per claim.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function mutations(): array {
		$with = static fn (array $change): array => [array_merge(self::claims(), $change)];
		$without = static function (string $claim): array {
			$claims = self::claims();
			unset($claims[$claim]);
			return [$claims];
		};

		return [
			'use of another token'         => $with(['use' => 'access']),
			'another issuer'               => $with(['iss' => 'someone-else']),
			'another consumer'             => $with(['audience' => 'portaliq-tilburg']),
			'another organisation'         => $with(['organisation' => 'gemeente-y']),
			'another provider'             => $with(['provider' => 'eherkenning']),
			'expired'                      => $with(['exp' => self::NOW, 'iat' => (self::NOW - 30)]),
			'lived longer than 60 seconds' => $with(['iat' => (self::NOW - 100), 'exp' => (self::NOW + 10)]),
			'no subject'                   => $with(['sub' => '']),
			'no use claim'                 => $without('use'),
			'no expiry'                    => $without('exp'),
		];
	}//end mutations()


	/**
	 * T06: each claim that does not match this login ends it.
	 *
	 * @param array<string, mixed> $claims The mutated claims.
	 *
	 * @return void
	 */
	#[DataProvider('mutations')]
	public function testAMismatchedClaimIsRefused(array $claims): void {
		$this->assertNull($this->check(self::envelope($claims)));
	}//end testAMismatchedClaimIsRefused()


	/**
	 * What is not a compact JWS is refused.
	 *
	 * @return void
	 */
	public function testNotAnEnvelopeIsRefused(): void {
		foreach (['', 'abc', 'a.b', 'a.!!!.c', 'a.' . base64_encode('"text"') . '.c'] as $token) {
			$this->assertNull($this->check($token), $token);
		}
	}//end testNotAnEnvelopeIsRefused()


	/**
	 * An unknown or non-text trust is under-privileged to low.
	 *
	 * @return void
	 */
	public function testAnUnknownTrustIsLow(): void {
		$this->assertSame('low', $this->check(self::envelope(array_merge(self::claims(), ['trust' => 'eidas-high'])))['trust']);
		$this->assertSame('low', $this->check(self::envelope(array_merge(self::claims(), ['trust' => 3])))['trust']);
		$this->assertSame('high', $this->check(self::envelope(array_merge(self::claims(), ['trust' => 'high'])))['trust']);
	}//end testAnUnknownTrustIsLow()
}//end class
