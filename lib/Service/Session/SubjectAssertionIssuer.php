<?php

/**
 * Portaliq Subject Assertion Issuer
 *
 * Mints the short-lived `X-Portal-Subject` assertion a resolved subject is
 * forwarded with, signed with the same dedicated secret as sessions. The
 * assertion carries `use: assertion`, so it never resolves as a session.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Session
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Session;

use RuntimeException;

/**
 * Mints `X-Portal-Subject` assertions for resolved subjects.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T7
 */
class SubjectAssertionIssuer {
	/**
	 * Constructor.
	 *
	 * @param SessionSigningKey $signingKey The signer, or none when unconfigured.
	 */
	public function __construct(
		private readonly SessionSigningKey $signingKey,
	) {
	}//end __construct()

	/**
	 * Whether a dedicated signing secret is configured, so an assertion can
	 * be minted at all.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.4
	 */
	public function isConfigured(): bool {
		return $this->signingKey->isConfigured();
	}//end isConfigured()

	/**
	 * Mint a short-lived `X-Portal-Subject` assertion for a resolved subject.
	 *
	 * Delegates to PortalJwtService::createAssertion() so the assertion uses
	 * exactly the same secret sourcing as sessions. The assertion carries the
	 * SESSION's jti (audit correlation to the originating, revocable session)
	 * plus `use: "assertion"`, which resolveFromBearer() rejects — an assertion
	 * can never come back as a portal session (contract v2, A6).
	 *
	 * @param array<string, mixed> $subject The resolved subject (from resolveFromBearer).
	 * @param string $scopeClaim The action's declared scope claim, or ''.
	 * @param string $scopeValue The server-resolved value of that claim, or ''.
	 *
	 * @return string Compact assertion JWT (TTL ~60s).
	 *
	 * @throws RuntimeException When no dedicated signing secret is configured
	 *                          — should be unreachable in practice, since a
	 *                          resolved subject implies `resolveFromBearer()`
	 *                          already required `$jwt` to be non-null.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T7
	 */
	public function issueAssertion(array $subject, string $scopeClaim = '', string $scopeValue = ''): string {
		$jwt = $this->signingKey->jwt();
		if ($jwt === null) {
			throw new RuntimeException('Portaliq: cannot issue an assertion — no dedicated jwt_signing_secret configured');
		}

		return $jwt->createAssertion(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			audience: (string)($subject['audience'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			trust: (new SessionTrust())->normaliseTrust(trust: ($subject['trust'] ?? '')),
			jti: (string)($subject['jti'] ?? ''),
			scopeClaim: $scopeClaim,
			scopeValue: $scopeValue,
			branch: (string)($subject['branch'] ?? '')
		);
	}//end issueAssertion()
}//end class
