<?php

/**
 * Portaliq OIDC Client Service
 *
 * A generic, broker-agnostic OIDC Relying Party (portal-oidc-broker-login,
 * design.md): OIDC discovery, PKCE (S256) generation, authorization-code
 * exchange, and FULL ID-token validation — issuer, audience, nonce, expiry,
 * and an RS256 signature verified against the broker's own JWKS (fetched from
 * the discovery document's `jwks_uri`, cached with a key-rotation retry).
 *
 * Self-contained on purpose (no JWT/JOSE composer dependency), mirroring
 * {@see PortalJwtService}'s rationale — the signature algorithm is fixed
 * (RS256 only; `alg: none` and every other algorithm is REJECTED, closing the
 * classic algorithm-confusion class of attack), and the JWK→PEM conversion is
 * the standard PKCS#1/X.509 SubjectPublicKeyInfo DER encoding for an RSA
 * public key, verified with PHP's own `openssl_verify()`.
 *
 * Fail-closed discipline (ADR-005, design.md): EVERY validation failure —
 * malformed token, wrong `alg`, unknown/ambiguous `kid`, bad signature, wrong
 * `iss`/`aud`/`nonce`, expired token, or an unreachable discovery/JWKS
 * endpoint — returns null from `validateIdTokenAgainstJwks()` /
 * `verifyIdToken()`. The caller (`SessionController::oidcCallback()`) maps
 * EVERY null to the SAME generic error, so no response ever reveals which
 * check failed (no oracle).
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
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one dependency per
 * distinct responsibility (HTTP, distributed cache, logging) — see
 * PortalSessionService's identical rationale.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generic broker-agnostic OIDC Relying Party: discovery, PKCE, token exchange,
 * and fail-closed RS256 ID-token validation via cached JWKS.
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- discovery, PKCE, token
 * exchange, JWKS fetch/cache, and the RS256/DER verification the JOSE-library-
 * free rationale (class docblock) requires all belong to ONE security
 * boundary (the OIDC RP); splitting them would scatter that boundary across
 * classes with no corresponding safety gain — see PortalObjectReader's
 * identical rationale.
 */
class OidcClientService
{
    /**
     * The ONLY accepted ID-token signature algorithm. Rejects `alg: none`
     * and every other algorithm — closes the classic algorithm-confusion
     * attack (e.g. presenting an HS256 token HMAC-signed with the RSA
     * public key's modulus as the "secret").
     */
    private const ACCEPTED_ALG = 'RS256';

    /**
     * Clock-skew tolerance for `exp`/`iat`, seconds.
     */
    private const CLOCK_SKEW = 60;

    /**
     * HTTP request timeout for discovery/token/JWKS calls, seconds.
     */
    private const HTTP_TIMEOUT = 10;

    /**
     * How long a fetched discovery document / JWKS is cached, seconds.
     */
    private const CACHE_TTL = 3600;

    /**
     * Distributed cache key prefix (namespacing this app's own entries).
     */
    private const CACHE_PREFIX = 'portaliq_oidc';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService HTTP client for discovery/token/JWKS calls.
     * @param ICacheFactory   $cacheFactory  Distributed cache for the discovery
     *                                       document and JWKS (best-effort — a
     *                                       cache miss/unavailable backend simply
     *                                       means every call re-fetches; never a
     *                                       correctness issue).
     * @param LoggerInterface $logger        The logger — debug-level only, never
     *                                       leaks WHICH validation check failed.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a fresh, cryptographically secure `state`/`nonce` token.
     *
     * @return string URL-safe, ~43 characters.
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
     */
    public function generateToken(): string
    {
        return $this->b64UrlEncode(bytes: random_bytes(32));
    }//end generateToken()

    /**
     * Generate a PKCE (S256) code verifier + its code challenge (RFC 7636).
     *
     * @return array{verifier: string, challenge: string}
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
     */
    public function generatePkce(): array
    {
        $verifier  = $this->b64UrlEncode(bytes: random_bytes(32));
        $challenge = $this->b64UrlEncode(bytes: hash('sha256', $verifier, true));

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }//end generatePkce()

    /**
     * Resolve the broker's OIDC discovery document (`.well-known/openid-
     * configuration`), cached. Fails closed to null on any HTTP/JSON error,
     * or when the required endpoints are absent from the document.
     *
     * @param string $issuer The configured issuer base URL.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string}|null
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
     */
    public function discover(string $issuer): ?array
    {
        if ($issuer === '') {
            return null;
        }

        $cache  = $this->cacheFactory->createDistributed(self::CACHE_PREFIX.'_discovery');
        $cached = $cache->get($issuer);
        if (is_array($cached) === true) {
            return $cached;
        }

        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get($url, ['timeout' => self::HTTP_TIMEOUT]);
        } catch (Throwable $e) {
            $this->logger->debug('Portaliq: OIDC discovery failed', ['reason' => $e->getMessage()]);
            return null;
        }

        $decoded = $this->decodeJsonBody(response: $response);
        if ($decoded === null) {
            return null;
        }

        $endpoints = [
            'authorization_endpoint' => (string) ($decoded['authorization_endpoint'] ?? ''),
            'token_endpoint'         => (string) ($decoded['token_endpoint'] ?? ''),
            'jwks_uri'               => (string) ($decoded['jwks_uri'] ?? ''),
        ];

        if ($endpoints['authorization_endpoint'] === '' || $endpoints['token_endpoint'] === '' || $endpoints['jwks_uri'] === '') {
            return null;
        }

        $cache->set($issuer, $endpoints, self::CACHE_TTL);

        return $endpoints;
    }//end discover()

    /**
     * Build the authorization-request URL (RFC 6749 + PKCE).
     *
     * @param string             $authorizeEndpoint The broker's authorization endpoint.
     * @param string             $clientId              This RP's client id.
     * @param string             $redirectUri           The callback URL.
     * @param array<int, string> $scopes                Requested scopes.
     * @param string             $state                 The CSRF state token.
     * @param string             $nonce                 The replay nonce.
     * @param string             $codeChallenge         The PKCE S256 code challenge.
     *
     * @return string
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
     */
    public function buildAuthorizationUrl(
        string $authorizeEndpoint,
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $state,
        string $nonce,
        string $codeChallenge
    ): string {
        $query = http_build_query(
            [
                'response_type'         => 'code',
                'client_id'             => $clientId,
                'redirect_uri'          => $redirectUri,
                'scope'                 => implode(' ', $scopes),
                'state'                 => $state,
                'nonce'                 => $nonce,
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
            ]
        );

        $separator = '?';
        if (str_contains($authorizeEndpoint, '?') === true) {
            $separator = '&';
        }

        return $authorizeEndpoint.$separator.$query;
    }//end buildAuthorizationUrl()

    /**
     * Exchange an authorization code for tokens at the broker's token endpoint.
     * Fails closed to null on any transport/HTTP/JSON error or a non-2xx
     * response — never surfaces the broker's own error detail to the caller.
     *
     * @param string $tokenEndpoint The broker's token endpoint.
     * @param string $code          The authorization code from the callback.
     * @param string $codeVerifier  The PKCE code verifier matching the original challenge.
     * @param string $clientId      This RP's client id.
     * @param string $clientSecret  This RP's client secret (never logged/returned).
     * @param string $redirectUri   The SAME redirect_uri used at `start`.
     *
     * @return array<string, mixed>|null The decoded token response, or null.
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
     */
    public function exchangeCode(
        string $tokenEndpoint,
        string $code,
        string $codeVerifier,
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ): ?array {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $tokenEndpoint,
                [
                    'timeout'     => self::HTTP_TIMEOUT,
                    'http_errors' => false,
                    'body'        => [
                        'grant_type'    => 'authorization_code',
                        'code'          => $code,
                        'redirect_uri'  => $redirectUri,
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'code_verifier' => $codeVerifier,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->debug('Portaliq: OIDC code exchange failed', ['reason' => $e->getMessage()]);
            return null;
        }//end try

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        return $this->decodeJsonBody(response: $response);
    }//end exchangeCode()

    /**
     * Full network-integrated ID-token verification: fetches (cached) JWKS
     * and validates the token against it, retrying ONCE with a forced cache
     * refresh on failure (key-rotation tolerance) before giving up.
     *
     * @param string $idToken       The ID token from the token response.
     * @param string $jwksUri       The broker's JWKS endpoint.
     * @param string $issuer        The configured issuer (MUST equal `iss`).
     * @param string $clientId      This RP's client id (MUST be in `aud`).
     * @param string $expectedNonce The nonce stored at `start`.
     *
     * @return array<string, mixed>|null The validated claim set, or null on
     *                                    ANY failure (fail-closed, no oracle).
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
     * @spec openspec/specs/supplier-portal/spec.md#oidc-callback-validates-the-id-token-and-fails-closed-on-every-error
     */
    public function verifyIdToken(string $idToken, string $jwksUri, string $issuer, string $clientId, string $expectedNonce): ?array
    {
        $jwks    = $this->fetchJwks(jwksUri: $jwksUri, forceRefresh: false);
        $jwksSet = ($jwks ?? ['keys' => []]);
        $claims  = $this->validateIdTokenAgainstJwks(
            idToken: $idToken,
            jwks: $jwksSet,
            issuer: $issuer,
            clientId: $clientId,
            expectedNonce: $expectedNonce
        );
        if ($claims !== null) {
            return $claims;
        }

        // Key-rotation tolerance: one forced refetch before failing closed.
        $refreshed = $this->fetchJwks(jwksUri: $jwksUri, forceRefresh: true);
        if ($refreshed === null) {
            return null;
        }

        return $this->validateIdTokenAgainstJwks(
            idToken: $idToken,
            jwks: $refreshed,
            issuer: $issuer,
            clientId: $clientId,
            expectedNonce: $expectedNonce
        );
    }//end verifyIdToken()

    /**
     * Fetch the broker's JWKS document, cached.
     *
     * @param string $jwksUri      The broker's JWKS endpoint.
     * @param bool   $forceRefresh Bypass the cache (key-rotation retry).
     *
     * @return array{keys: array<int, mixed>}|null
     */
    private function fetchJwks(string $jwksUri, bool $forceRefresh): ?array
    {
        if ($jwksUri === '') {
            return null;
        }

        $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX.'_jwks');
        if ($forceRefresh === false) {
            $cached = $cache->get($jwksUri);
            if (is_array($cached) === true) {
                return $cached;
            }
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get($jwksUri, ['timeout' => self::HTTP_TIMEOUT]);
        } catch (Throwable $e) {
            $this->logger->debug('Portaliq: OIDC JWKS fetch failed', ['reason' => $e->getMessage()]);
            return null;
        }

        $decoded = $this->decodeJsonBody(response: $response);
        if ($decoded === null || is_array(($decoded['keys'] ?? null)) === false) {
            return null;
        }

        $cache->set($jwksUri, $decoded, self::CACHE_TTL);

        return $decoded;
    }//end fetchJwks()

    /**
     * Validate an ID token against an in-memory JWKS — the pure, network-free
     * core so it can be exhaustively unit tested with crafted tokens/keys.
     *
     * ALL of the following are mandatory; ANY failure returns null:
     *   - well-formed 3-part compact JWT;
     *   - header `alg` is EXACTLY `RS256` (rejects `alg: none` and every
     *     other algorithm — no algorithm confusion);
     *   - the `kid` (or, absent one, the SOLE RSA key in the JWKS) resolves
     *     to exactly one signing key — ambiguous/absent resolution fails closed;
     *   - the RS256 signature verifies against that key;
     *   - `iss` equals the configured issuer;
     *   - `aud` contains (or equals) the configured clientId;
     *   - `nonce` equals the expected nonce;
     *   - `exp` is in the future (within clock skew) and `iat` is not in the
     *     future beyond clock skew.
     *
     * @param string                                        $idToken       The compact JWT.
     * @param array{keys: array<int, mixed>} $jwks          The JWKS to verify against.
     * @param string                                        $issuer        The configured issuer.
     * @param string                                        $clientId      This RP's client id.
     * @param string                                        $expectedNonce The nonce stored at `start`.
     *
     * @return array<string, mixed>|null The validated claim set, or null.
     *
     * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
     * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
     */
    public function validateIdTokenAgainstJwks(string $idToken, array $jwks, string $issuer, string $clientId, string $expectedNonce): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerPart, $claimsPart, $signaturePart] = $parts;

        $header = $this->decodeJsonSegment(segment: $headerPart);
        $claims = $this->decodeJsonSegment(segment: $claimsPart);
        if ($header === null || $claims === null) {
            return null;
        }

        // Strict allow-list — rejects `alg: none` and every non-RS256 value,
        // including a same-length HS256 confusion attempt.
        if (($header['alg'] ?? '') !== self::ACCEPTED_ALG) {
            return null;
        }

        $publicKeyPem = $this->resolveSigningKeyPem(jwks: $jwks, kid: (string) ($header['kid'] ?? ''));
        if ($publicKeyPem === null) {
            return null;
        }

        $signature = $this->b64UrlDecode(encoded: $signaturePart);
        $verified  = openssl_verify($headerPart.'.'.$claimsPart, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return null;
        }

        if ($this->claimsAreValid(claims: $claims, issuer: $issuer, clientId: $clientId, expectedNonce: $expectedNonce) === false) {
            return null;
        }

        return $claims;
    }//end validateIdTokenAgainstJwks()

    /**
     * The claim-level checks (iss/aud/nonce/exp/iat) once the signature has
     * already verified. Split out purely for readability; every branch still
     * collapses to the SAME boolean the caller treats identically.
     *
     * @param array<string, mixed> $claims        The decoded ID token claims.
     * @param string               $issuer        The configured issuer.
     * @param string               $clientId      This RP's client id.
     * @param string               $expectedNonce The nonce stored at `start`.
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- five independent
     * fail-closed guards (iss/aud/nonce/exp/iat), each mandatory per design.md
     * — collapsing them into fewer branches would trade auditability for a
     * score, mirroring PortalSessionService's identical rationale.
     */
    private function claimsAreValid(array $claims, string $issuer, string $clientId, string $expectedNonce): bool
    {
        if (($claims['iss'] ?? null) !== $issuer) {
            return false;
        }

        $aud        = ($claims['aud'] ?? null);
        $audMatches = ($aud === $clientId) || (is_array($aud) === true && in_array($clientId, $aud, true) === true);
        if ($audMatches === false) {
            return false;
        }

        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            return false;
        }

        $now = time();
        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp === 0 || $now > ($exp + self::CLOCK_SKEW)) {
            return false;
        }

        $iat = (int) ($claims['iat'] ?? 0);
        if ($iat !== 0 && $iat > ($now + self::CLOCK_SKEW)) {
            return false;
        }

        return true;
    }//end claimsAreValid()

    /**
     * Resolve the RSA public key PEM for a `kid` from a JWKS. When the token
     * carries no `kid`, this only succeeds if the JWKS holds EXACTLY ONE
     * RSA signing key — an ambiguous match fails closed rather than guessing.
     *
     * @param array{keys: array<int, mixed>} $jwks The JWKS.
     * @param string                                        $kid  The token's `kid` header (may be empty).
     *
     * @return string|null PEM-encoded RSA public key, or null when unresolvable.
     */
    private function resolveSigningKeyPem(array $jwks, string $kid): ?string
    {
        $candidates = [];
        foreach ($jwks['keys'] as $key) {
            if (is_array($key) === false || ($key['kty'] ?? '') !== 'RSA') {
                continue;
            }

            if ($kid !== '' && ($key['kid'] ?? '') !== $kid) {
                continue;
            }

            $candidates[] = $key;
        }

        if (count($candidates) !== 1) {
            return null;
        }

        $n = (string) ($candidates[0]['n'] ?? '');
        $e = (string) ($candidates[0]['e'] ?? '');
        if ($n === '' || $e === '') {
            return null;
        }

        return $this->rsaJwkToPem(nB64Url: $n, eB64Url: $e);
    }//end resolveSigningKeyPem()

    /**
     * Build a PEM-encoded X.509 SubjectPublicKeyInfo for an RSA public key
     * from its JWK `n` (modulus) / `e` (exponent), base64url-encoded.
     *
     * Standard PKCS#1 `RSAPublicKey ::= SEQUENCE { n INTEGER, e INTEGER }`
     * wrapped in the standard X.509 `SubjectPublicKeyInfo` (rsaEncryption
     * OID + NULL params, BIT STRING payload) — the same encoding
     * `openssl_pkey_get_public()` expects for a `-----BEGIN PUBLIC KEY-----`
     * PEM. There is no `ext-jwk`/composer JOSE dependency in this app
     * (mirrors `PortalJwtService`'s self-contained rationale), so this is a
     * small, self-contained DER builder rather than a new dependency.
     *
     * @param string $nB64Url Base64url-encoded modulus.
     * @param string $eB64Url Base64url-encoded exponent.
     *
     * @return string PEM-encoded public key.
     */
    private function rsaJwkToPem(string $nB64Url, string $eB64Url): string
    {
        $n = $this->b64UrlDecode(encoded: $nB64Url);
        $e = $this->b64UrlDecode(encoded: $eB64Url);

        $rsaPublicKey = $this->derSequence(content: $this->derInteger(bytes: $n).$this->derInteger(bytes: $e));
        $bitString    = $this->derBitString(content: $rsaPublicKey);
        // AlgorithmIdentifier for rsaEncryption (1.2.840.113549.1.1.1) + NULL.
        $algorithmId = hex2bin('300d06092a864886f70d0101010500');
        $spki        = $this->derSequence(content: $algorithmId.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }//end rsaJwkToPem()

    /**
     * DER-encode a length (short or long form).
     *
     * @param int $length The content length in bytes.
     *
     * @return string
     */
    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }//end derLength()

    /**
     * DER-encode an unsigned big-endian integer, prefixing a `0x00` byte
     * when the high bit is set (so it is never misread as negative).
     *
     * @param string $bytes Raw big-endian magnitude bytes.
     *
     * @return string
     */
    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }

        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLength(length: strlen($bytes)).$bytes;
    }//end derInteger()

    /**
     * DER-encode a SEQUENCE.
     *
     * @param string $content The already-encoded inner content.
     *
     * @return string
     */
    private function derSequence(string $content): string
    {
        return "\x30".$this->derLength(length: strlen($content)).$content;
    }//end derSequence()

    /**
     * DER-encode a BIT STRING with zero unused bits.
     *
     * @param string $content The raw bit-string payload.
     *
     * @return string
     */
    private function derBitString(string $content): string
    {
        return "\x03".$this->derLength(length: (strlen($content) + 1))."\x00".$content;
    }//end derBitString()

    /**
     * Decode + json_decode an HTTP response body, or null on any failure.
     *
     * @param mixed $response The HTTP response (duck-typed `getBody()`).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(mixed $response): ?array
    {
        try {
            $body = $response->getBody();
        } catch (Throwable) {
            return null;
        }

        if (is_string($body) === false || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return $decoded;
    }//end decodeJsonBody()

    /**
     * Base64url-decode one JWT segment and json_decode it, or null on failure.
     *
     * @param string $segment The base64url-encoded segment.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonSegment(string $segment): ?array
    {
        $decoded = json_decode($this->b64UrlDecode(encoded: $segment), true);
        if (is_array($decoded) === false) {
            return null;
        }

        return $decoded;
    }//end decodeJsonSegment()

    /**
     * Base64-url encode (no padding).
     *
     * @param string $bytes Raw bytes.
     *
     * @return string
     */
    private function b64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }//end b64UrlEncode()

    /**
     * Base64-url decode.
     *
     * @param string $encoded Encoded string.
     *
     * @return string Raw bytes.
     */
    private function b64UrlDecode(string $encoded): string
    {
        $pad = (4 - (strlen($encoded) % 4));
        if ($pad < 4) {
            $encoded .= str_repeat('=', $pad);
        }

        return (string) base64_decode(strtr($encoded, '-_', '+/'));
    }//end b64UrlDecode()
}//end class
