<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Signin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Signin;

/**
 * The claims of an integriq subject envelope, checked before portaliq acts on
 * any of them (REQ-BEL-004, design D5).
 *
 * The envelope is HS256 under integriq's own key. Portaliq does not hold that
 * key, because a holder of it can mint envelopes; it trusts the envelope for
 * the channel it came over (TLS to the configured exchange address, portaliq's
 * own secret, redeemed once) and checks every claim it acts on. Any mismatch
 * answers null, and the caller ends the login the same way as every other
 * failure.
 *
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-every-claim-portaliq-acts-on-is-checked-req-bel-004
 */
class BrokerEnvelopeCheck {

	/**
	 * The `use` claim integriq sets on a subject envelope.
	 */
	public const USE_CLAIM = 'idp-envelope';

	/**
	 * The issuer integriq names.
	 */
	public const ISSUER = 'openconnector-idp-broker';

	/**
	 * The longest life integriq gives an envelope, in seconds.
	 */
	public const MAX_TTL_SECONDS = 60;


	/**
	 * The envelope's claims when every one portaliq acts on matches, else null.
	 *
	 * @param string    The compact JWS integriq returned.
	 * @param string  Portaliq's consumer id: the envelope's audience.
	 * @param string         The organisation the login was started for.
	 * @param string    The provider the login was started for.
	 * @param int    $now        The current unix time.
	 *
	 * @return array{sub: string, provider: string, trust: string}|null
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-every-claim-portaliq-acts-on-is-checked-req-bel-004
	 */
	public function check(string $envelope, string $consumerId, string $org, string $provider, int $now): ?array {
		$claims = $this->claims(envelope: $envelope);
		if ($claims === null) {
			return null;
		}

		$expected = [
			'use' => self::USE_CLAIM,
			'iss' => self::ISSUER,
			'audience' => $consumerId,
			'organisation' => $org,
			'provider' => $provider,
		];
		foreach ($expected as $claim => $value) {
			if ($value === '' || ($claims[$claim] ?? null) !== $value) {
				return null;
			}
		}

		if ($this->isCurrent(claims: $claims, now: $now) === false) {
			return null;
		}

		$subject = ($claims['sub'] ?? null);
		if (is_string($subject) === false || trim($subject) === '') {
			return null;
		}

		// An unknown or missing trust passes through as text and becomes `low`
		// in PortalSessionService::normaliseTrust(), as integriq's spec asks.
		$trust = '';
		if (is_string($claims['trust'] ?? null) === true) {
			$trust = $claims['trust'];
		}

		return ['sub' => $subject, 'provider' => $provider, 'trust' => $trust];
	}//end check()


	/**
	 * Whether the envelope has not expired and lived no longer than integriq
	 * allows. No extra clock skew: a slow exchange that expires in transit is
	 * an ordinary failed login.
	 *
	 * @param array<string, mixed> $claims The claims.
	 * @param int                  $now    The current unix time.
	 *
	 * @return bool
	 */
	private function isCurrent(array $claims, int $now): bool {
		$expiry = ($claims['exp'] ?? null);
		$issuedAt = ($claims['iat'] ?? null);
		if (is_int($expiry) === false || is_int($issuedAt) === false) {
			return false;
		}

		return $expiry > $now && ($expiry - $issuedAt) <= self::MAX_TTL_SECONDS;
	}//end isCurrent()


	/**
	 * The payload of a compact JWS, or null when it is not one.
	 *
	 * @param string $envelope The token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function claims(string $envelope): ?array {
		$parts = explode('.', $envelope);
		if (count($parts) !== 3) {
			return null;
		}

		$json = base64_decode(strtr($parts[1], '-_', '+/'), true);
		if ($json === false) {
			return null;
		}

		$claims = json_decode($json, true);
		if (is_array($claims) === false) {
			return null;
		}

		return $claims;
	}//end claims()
}//end class
