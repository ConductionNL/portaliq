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
class PortalJwtService
{
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
     * @param string             $subjectRef   Server-derived subject reference (e.g. supplierRef).
     * @param string             $audience     External audience ("supplier"|"client").
     * @param string             $organisation Tenant (OpenRegister Organisation) the session is scoped to.
     * @param string             $jti          Unique token id (for revocation).
     * @param string             $trust        Assurance level (e.g. "EH3"); empty when not applicable.
     * @param array<int, string> $roles        Roles carried inside the session.
     * @param int|null           $ttl          Override the default TTL (seconds).
     *
     * @return string Compact JWT string.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    public function createSession(
        string $subjectRef,
        string $audience,
        string $organisation,
        string $jti,
        string $trust='',
        array $roles=[],
        ?int $ttl=null
    ): string {
        $iat = time();
        $exp = ($iat + ($ttl ?? self::DEFAULT_TTL));

        $header = ['alg' => self::ALG, 'typ' => 'JWT'];
        $claims = [
            'sub'          => $subjectRef,
            'audience'     => $audience,
            'organisation' => $organisation,
            'trust'        => $trust,
            'roles'        => array_values($roles),
            'jti'          => $jti,
            'iat'          => $iat,
            'exp'          => $exp,
            'iss'          => self::ISSUER,
        ];

        $hPart = $this->b64UrlEncode(bytes: (string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $cPart = $this->b64UrlEncode(bytes: (string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig   = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart.'.'.$cPart));
        return $hPart.'.'.$cPart.'.'.$sig;
    }//end createSession()

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
    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed portal JWT');
        }

        [$hPart, $cPart, $sPart] = $parts;

        $expected = $this->b64UrlEncode(bytes: $this->signRaw(input: $hPart.'.'.$cPart));
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

        if (isset($claims['exp']) === true && (int) $claims['exp'] < time()) {
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
    private function signRaw(string $input): string
    {
        return hash_hmac(self::HASH_FN, $input, $this->signingSecret, true);
    }//end signRaw()

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
