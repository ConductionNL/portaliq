<?php

/**
 * Portaliq Portal JWT Service
 *
 * Self-contained HMAC (HS256) JWT encode/decode for the portal auth edge. Mints
 * and validates the bearer sessions Portaliq issues to external subjects
 * (suppliers and clients), carrying `sub` (subjectRef), `audience`,
 * `organisation`, `trust`, `roles`, and `jti` claims.
 *
 * HS256 is deliberate — it mirrors procest's proven supplier-auth token shape
 * and the OpenRegister Consumer secret model. The signing secret comes from
 * Portaliq configuration (never from the request), so a forged signature cannot
 * pass verification. This class has no Nextcloud dependencies so it can be unit
 * tested in isolation.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T7
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * HMAC-based JWT minting + validation for Portaliq's audience-agnostic auth edge.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalJwtService {
	/**
	 * HMAC algorithm — HS256.
	 */
	public const ALG = 'HS256';

	/**
	 * Hash function name passed to hash_hmac.
	 */
	private const HASH_FN = 'sha256';

	/**
	 * Token validity window in seconds (default 2 hours, matching procest's
	 * supplier session TTL).
	 */
	public const DEFAULT_TTL = 7200;

	/**
	 * Subject-assertion validity window in seconds (contract v2, A6). Short by
	 * design: the assertion only has to survive one server-to-server forward.
	 */
	public const ASSERTION_TTL = 60;

	/**
	 * The `use` claim value marking an X-Portal-Subject assertion. Tokens
	 * carrying it are REJECTED by PortalSessionService::resolveFromBearer().
	 */
	public const USE_ASSERTION = 'assertion';

	/**
	 * The nine frozen assertion claims. A declared scope claim may never take
	 * one of these names (case-actions-sign-a-document D3).
	 *
	 * @var string[]
	 */
	public const RESERVED_ASSERTION_CLAIMS = ['sub', 'audience', 'organisation', 'trust', 'jti', 'use', 'iat', 'exp', 'iss'];

	/**
	 * The `use` claim value marking a reference session (identity-ways-in-screens
	 * D2). `resolveFromBearer()` refuses it like every special-use token.
	 */
	public const USE_REFERENCE = 'reference';

	/**
	 * A reference session's lifetime in seconds: thirty minutes, never refreshed.
	 */
	public const REFERENCE_TTL = 1800;

	/**
	 * Token issuer claim.
	 */
	private const ISSUER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param string $signingSecret Server-side HMAC signing secret (>= 16 chars).
	 *
	 * @throws InvalidArgumentException When the secret is too short.
	 */
	public function __construct(
		private readonly string $signingSecret,
	) {
		if (strlen($this->signingSecret) < 16) {
			throw new InvalidArgumentException('Portal JWT signing secret too short (<16 chars)');
		}
	}//end __construct()

	/**
	 * Mint a portal session token.
	 *
	 * @param string $subjectRef Server-derived subject reference (e.g. supplierRef).
	 * @param string $audience External audience ("supplier"|"client").
	 * @param string $organisation Tenant (OpenRegister Organisation) the session is scoped to.
	 * @param string $jti Unique token id (for revocation).
	 * @param string $trust Assurance level (e.g. "EH3"); empty when not applicable.
	 * @param array<int, string> $roles Roles carried inside the session.
	 * @param int|null $ttl Override the default TTL (seconds).
	 * @param int|null $authTime Unix timestamp of the ORIGINAL login this session
	 *                           chain descends from (portal-session-hardening-v2).
	 *                           Carried forward unchanged across a refresh rotation
	 *                           so the absolute session lifetime can be enforced from
	 *                           the true origin, not the most recent mint. Defaults to
	 *                           `$iat` (a fresh login) when not supplied.
	 *
	 * @return string Compact JWT string.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T01
	 */
	public function createSession(
		string $subjectRef,
		string $audience,
		string $organisation,
		string $jti,
		string $trust = '',
		array $roles = [],
		?int $ttl = null,
		?int $authTime = null,
	): string {
		$iat = time();
		$exp = ($iat + ($ttl ?? self::DEFAULT_TTL));

		$header = ['alg' => self::ALG, 'typ' => 'JWT'];
		$claims = [
			'sub' => $subjectRef,
			'audience' => $audience,
			'organisation' => $organisation,
			'trust' => $trust,
			'roles' => array_values($roles),
			'jti' => $jti,
			'iat' => $iat,
			'exp' => $exp,
			'iss' => self::ISSUER,
			// The ORIGIN login's timestamp — unchanged by refresh — so the
			// absolute session lifetime cap is measured from the true start of
			// the session chain (portal-session-hardening-v2, design.md).
			'authTime' => ($authTime ?? $iat),
		];

		$hPart = $this->b64UrlEncode(bytes: (string)json_encode($header, JSON_UNESCAPED_SLASHES));
		$cPart = $this->b64UrlEncode(bytes: (string)json_encode($claims, JSON_UNESCAPED_SLASHES));
		$sig = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart . '.' . $cPart));
		return $hPart . '.' . $cPart . '.' . $sig;
	}//end createSession()

	/**
	 * Mint a short-lived `X-Portal-Subject` assertion (contract v2, A6).
	 *
	 * Distinct from a session token: it carries `use: "assertion"` (rejected by
	 * the session resolver — token-confusion guard) and the ORIGINATING
	 * session's `jti`, so the receiving app can correlate the action to the
	 * revocable session for audit. Same HS256 signing + secret as sessions.
	 *
	 * @param string $subjectRef Server-derived subject reference.
	 * @param string $audience External audience of the subject.
	 * @param string $organisation Tenant the subject is scoped to.
	 * @param string $trust Normalised trust level (`low|substantial|high`).
	 * @param string $jti The originating SESSION's token id.
	 * @param int|null $ttl Override the assertion TTL (seconds).
	 * @param string $scopeClaim The action's declared scope claim (`app.claimName` or `claimName`), or ''.
	 * @param string $scopeValue The server-resolved value of that claim, or ''.
	 *
	 * @return string Compact JWT string.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T7
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	public function createAssertion(
		string $subjectRef,
		string $audience,
		string $organisation,
		string $trust,
		string $jti,
		?int $ttl = null,
		string $scopeClaim = '',
		string $scopeValue = '',
	): string {
		$iat = time();
		$exp = ($iat + ($ttl ?? self::ASSERTION_TTL));

		$header = ['alg' => self::ALG, 'typ' => 'JWT'];
		$claims = [
			'sub' => $subjectRef,
			'audience' => $audience,
			'organisation' => $organisation,
			'trust' => $trust,
			'jti' => $jti,
			'use' => self::USE_ASSERTION,
			'iat' => $iat,
			'exp' => $exp,
			'iss' => self::ISSUER,
		];

		$claimName = self::scopeClaimName(scopeClaim: $scopeClaim);
		if ($claimName !== null && $scopeValue !== '') {
			$claims[$claimName] = $scopeValue;
		}

		$hPart = $this->b64UrlEncode(bytes: (string)json_encode($header, JSON_UNESCAPED_SLASHES));
		$cPart = $this->b64UrlEncode(bytes: (string)json_encode($claims, JSON_UNESCAPED_SLASHES));
		$sig = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart . '.' . $cPart));
		return $hPart . '.' . $cPart . '.' . $sig;
	}//end createAssertion()

	/**
	 * The assertion claim name a declared scope claim maps to.
	 *
	 * The part after the first `.` (the app prefix) is the name. It is null
	 * when the name is malformed or is one of the nine frozen claims, so a
	 * scope claim can never overwrite `sub`, `iss` or any other of them.
	 *
	 * @param string $scopeClaim The declared scope claim.
	 *
	 * @return string|null The claim name, or null when none may be added.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	public static function scopeClaimName(string $scopeClaim): ?string {
		$name = self::bareClaimName(scopeClaim: $scopeClaim);
		if (preg_match('/^[a-z][a-zA-Z0-9_]*$/', $name) !== 1) {
			return null;
		}

		if (in_array($name, self::RESERVED_ASSERTION_CLAIMS, true) === true) {
			return null;
		}

		return $name;
	}//end scopeClaimName()

	/**
	 * Whether a declared scope claim names one of the nine frozen assertion
	 * claims (case-actions-sign-a-document T03). Anything that is not a
	 * non-empty string is not a declared claim and so not reserved.
	 *
	 * @param mixed $scopeClaim The declared `scopeClaim` value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-28-case-actions-sign-a-document/tasks.md#T03
	 */
	public static function isReservedScopeClaim(mixed $scopeClaim): bool {
		if (is_string($scopeClaim) === false || $scopeClaim === '') {
			return false;
		}

		return in_array(self::bareClaimName(scopeClaim: $scopeClaim), self::RESERVED_ASSERTION_CLAIMS, true);
	}//end isReservedScopeClaim()

	/**
	 * A scope claim without its app prefix: the part after the first `.`.
	 *
	 * @param string $scopeClaim The declared scope claim.
	 *
	 * @return string
	 */
	private static function bareClaimName(string $scopeClaim): string {
		$dot = strpos($scopeClaim, '.');
		if ($dot === false) {
			return $scopeClaim;
		}

		return substr($scopeClaim, ($dot + 1));
	}//end bareClaimName()

	/**
	 * Mint a reference session: read-only access to one case, for thirty
	 * minutes (identity-ways-in-screens D2). It carries `use: reference`, so
	 * the portal session resolver refuses it on every other route.
	 *
	 * @param string $subjectRef `reference:<hash of the link id>`.
	 * @param string $organisation The tenant.
	 * @param string $caseReference The case number it may read.
	 * @param string $register The case collection's register.
	 * @param string $schema The case collection's schema.
	 * @param string $jti Unique token id (for revocation).
	 *
	 * @return string Compact JWT string.
	 *
	 * @spec openspec/changes/identity-ways-in-screens/design.md
	 */
	public function createReferenceSession(
		string $subjectRef,
		string $organisation,
		string $caseReference,
		string $register,
		string $schema,
		string $jti,
	): string {
		$iat = time();

		$header = ['alg' => self::ALG, 'typ' => 'JWT'];
		$claims = [
			'sub' => $subjectRef,
			'audience' => 'client',
			'organisation' => $organisation,
			'trust' => 'low',
			'roles' => [],
			'jti' => $jti,
			'use' => self::USE_REFERENCE,
			'caseReference' => $caseReference,
			'register' => $register,
			'schema' => $schema,
			'iat' => $iat,
			'exp' => ($iat + self::REFERENCE_TTL),
			'iss' => self::ISSUER,
		];

		$hPart = $this->b64UrlEncode(bytes: (string)json_encode($header, JSON_UNESCAPED_SLASHES));
		$cPart = $this->b64UrlEncode(bytes: (string)json_encode($claims, JSON_UNESCAPED_SLASHES));
		$sig = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart . '.' . $cPart));
		return $hPart . '.' . $cPart . '.' . $sig;
	}//end createReferenceSession()

	/**
	 * Validate a JWT and return its claims.
	 *
	 * @param string $token Compact JWT.
	 *
	 * @return array<string, mixed> Claim set.
	 *
	 * @throws RuntimeException When the token is malformed, the signature does
	 *                          not match, the issuer is wrong, or it is expired.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 */
	public function validate(string $token): array {
		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			throw new RuntimeException('Malformed portal JWT');
		}

		[$hPart, $cPart, $sPart] = $parts;

		$expected = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart . '.' . $cPart));
		if (hash_equals($expected, $sPart) === false) {
			throw new RuntimeException('Invalid portal JWT signature');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $cPart), true);
		if (is_array($claims) === false) {
			throw new RuntimeException('Malformed portal JWT claims');
		}

		if (($claims['iss'] ?? '') !== self::ISSUER) {
			throw new RuntimeException('Unexpected portal JWT issuer');
		}

		if (isset($claims['exp']) === true && (int)$claims['exp'] < time()) {
			throw new RuntimeException('Expired portal JWT');
		}

		return $claims;
	}//end validate()

	/**
	 * Raw HMAC of the signing input.
	 *
	 * @param string $input Signing input (header.payload).
	 *
	 * @return string Raw HMAC bytes.
	 */
	private function signRaw(string $input): string {
		return hash_hmac(self::HASH_FN, $input, $this->signingSecret, true);
	}//end signRaw()

	/**
	 * Base64-url encode (no padding).
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * Base64-url decode.
	 *
	 * @param string $encoded Encoded string.
	 *
	 * @return string Raw bytes.
	 */
	private function b64UrlDecode(string $encoded): string {
		$pad = (4 - (strlen($encoded) % 4));
		if ($pad < 4) {
			$encoded .= str_repeat('=', $pad);
		}

		return (string)base64_decode(strtr($encoded, '-_', '+/'));
	}//end b64UrlDecode()
}//end class
