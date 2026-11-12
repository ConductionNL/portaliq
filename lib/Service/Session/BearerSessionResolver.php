<?php

/**
 * Portaliq Bearer Session Resolver
 *
 * Resolves an incoming Authorization bearer back to a server-derived subject,
 * or to the reference a reference session may read. Resolution FAILS CLOSED:
 * any malformed / forged / expired / missing / revoked bearer yields null,
 * never a partial identity, and the subject is only ever taken from the
 * validated token (IDOR / ADR-005).
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

use OCA\Portaliq\Service\PortalJwtService;
use OCA\Portaliq\Service\SessionClaims;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves portal and reference session bearers (fail-closed).
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class BearerSessionResolver {
	/**
	 * The bearer scheme prefix, case-sensitive per RFC 6750.
	 */
	private const BEARER_PREFIX = 'Bearer ';

	/**
	 * Constructor.
	 *
	 * @param SessionSigningKey $signingKey The signer, or none when unconfigured.
	 * @param SessionRecords    $records    Tells a live session from a revoked one.
	 * @param LoggerInterface   $logger     Records why a bearer was rejected (debug).
	 */
	public function __construct(
		private readonly SessionSigningKey $signingKey,
		private readonly SessionRecords $records,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve an Authorization header to a server-derived subject. FAILS CLOSED.
	 *
	 * Fails closed when no dedicated signing secret is configured, when the
	 * bearer is absent/malformed/forged/expired, or when the token's `jti` has
	 * no matching (non-revoked) `portalSession` row — a revoked or unknown jti
	 * is rejected exactly like a bad signature, including when the lookup
	 * itself fails (OpenRegister unavailable never means "not revoked").
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 *
	 * @return array<string, mixed>|null The subject claim set, or null when the
	 *                                   bearer is absent, malformed, forged,
	 *                                   expired, or revoked.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.3
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.3
	 */
	public function resolveFromBearer(?string $authorizationHeader): ?array {
		if ($this->signingKey->jwt() === null || $this->hasBearer(authorizationHeader: $authorizationHeader) === false) {
			return null;
		}

		$claims = $this->sessionClaimsOf(jwt: $this->signingKey->jwt(), token: substr($authorizationHeader, strlen(self::BEARER_PREFIX)));
		if ($claims === null) {
			return null;
		}

		return (new SessionClaims())->subject(claims: $claims, trust: SessionTrust::normaliseTrust(trust: ($claims['trust'] ?? '')));
	}//end resolveFromBearer()

	/**
	 * Whether the header carries a bearer.
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true string $authorizationHeader
	 */
	private function hasBearer(?string $authorizationHeader): bool {
		return $authorizationHeader !== null && str_starts_with($authorizationHeader, self::BEARER_PREFIX) === true;
	}//end hasBearer()

	/**
	 * The claims of a bearer token when it validates as a live portal
	 * session, else null.
	 *
	 * @param PortalJwtService $jwt   The configured signer.
	 * @param string           $token The bearer token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function sessionClaimsOf(PortalJwtService $jwt, string $token): ?array {
		if ($token === '') {
			return null;
		}

		try {
			$claims = $jwt->validate($token);
		} catch (Throwable $e) {
			// Fail closed — never leak why. Debug-level only.
			$this->logger->debug('Portaliq: bearer rejected', ['reason' => $e->getMessage()]);
			return null;
		}

		return $this->activeSessionClaims(claims: $claims);
	}//end sessionClaimsOf()

	/**
	 * The claims when they are a portal session whose `jti` is still live,
	 * else null.
	 *
	 * @param array<string, mixed> $claims The validated token claims.
	 *
	 * @return array<string, mixed>|null
	 */
	private function activeSessionClaims(array $claims): ?array {
		// Token-confusion guard (contract v2, A6): a subject assertion — or any
		// future special-use token — is NOT a portal session. A relayed or
		// leaked X-Portal-Subject assertion can never be replayed as a bearer.
		if (($claims['use'] ?? '') !== '') {
			$this->logger->debug('Portaliq: bearer rejected', ['reason' => 'special-use token presented as session']);
			return null;
		}

		if ($this->records->isJtiActive(jti: (string)($claims['jti'] ?? '')) === false) {
			$this->logger->debug('Portaliq: bearer rejected', ['reason' => 'revoked or unknown jti']);
			return null;
		}

		return $claims;
	}//end activeSessionClaims()

	/**
	 * Resolve a reference session, and only a reference session. FAILS CLOSED.
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 *
	 * @return array{subjectRef: string, organisation: string, caseReference: string, register: string, schema: string, jti: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function resolveReferenceFromBearer(?string $authorizationHeader): ?array {
		if ($this->signingKey->jwt() === null || $this->hasBearer(authorizationHeader: $authorizationHeader) === false) {
			return null;
		}

		return $this->referenceOfToken(jwt: $this->signingKey->jwt(), token: substr($authorizationHeader, strlen(self::BEARER_PREFIX)));
	}//end resolveReferenceFromBearer()

	/**
	 * The reference a bearer token carries when it validates as a live
	 * reference session, else null.
	 *
	 * @param PortalJwtService $jwt   The configured signer.
	 * @param string           $token The bearer token.
	 *
	 * @return array{subjectRef: string, organisation: string, caseReference: string, register: string, schema: string, jti: string}|null
	 */
	private function referenceOfToken(PortalJwtService $jwt, string $token): ?array {
		try {
			$claims = $jwt->validate($token);
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: reference bearer rejected', ['reason' => $e->getMessage()]);
			return null;
		}

		if (($claims['use'] ?? '') !== PortalJwtService::USE_REFERENCE) {
			return null;
		}

		return $this->liveReference(claims: $claims);
	}//end referenceOfToken()

	/**
	 * The reference of a reference session's claims when its `jti` is live
	 * and every field is present, else null.
	 *
	 * @param array<string, mixed> $claims The reference session's claims.
	 *
	 * @return array{subjectRef: string, organisation: string, caseReference: string, register: string, schema: string, jti: string}|null
	 */
	private function liveReference(array $claims): ?array {
		if ($this->records->isJtiActive(jti: (string)($claims['jti'] ?? '')) === false) {
			return null;
		}

		$reference = (new SessionClaims())->reference(claims: $claims);
		if (in_array('', $reference, true) === true) {
			return null;
		}

		return $reference;
	}//end liveReference()
}//end class
