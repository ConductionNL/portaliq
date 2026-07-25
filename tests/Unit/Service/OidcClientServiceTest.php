<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\OidcClientService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * portal-oidc-broker-login T04: the FULL ID-token validation matrix, against
 * REAL RSA keypairs and REAL signatures — not stubbed crypto. Every single-
 * check failure (wrong iss/aud/nonce, expired token, bad signature, `alg:
 * none`, an unknown/ambiguous `kid`) is proven to fail closed (null); a token
 * passing every check is proven to validate. `PortalSessionService`-style
 * fail-closed discipline: the CALLER maps every null to the SAME generic
 * error, so this test only needs to prove null vs. non-null, never a
 * per-failure message (there isn't one — that IS the invariant).
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T03
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T10
 * @spec openspec/specs/supplier-portal/spec.md#oidc-callback-validates-the-id-token-and-fails-closed-on-every-error
 */
class OidcClientServiceTest extends TestCase
{

    private const ISSUER    = 'https://broker.example';
    private const CLIENT_ID = 'rp-client-1';
    private const NONCE     = 'expected-nonce-123';
    private const KID       = 'key-1';

    /**
     * @var resource|\OpenSSLAsymmetricKey
     */
    private $privateKey;

    /**
     * @var array{n: string, e: string}
     */
    private array $jwkComponents;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        $this->assertNotFalse($keyPair, 'test fixture: RSA keypair generation must succeed');
        $this->privateKey = $keyPair;

        $details = openssl_pkey_get_details($keyPair);
        $this->jwkComponents = [
            'n' => $this->b64Url($details['rsa']['n']),
            'e' => $this->b64Url($details['rsa']['e']),
        ];

    }//end setUp()

    public function testAValidTokenPassesEveryCheck(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: []);

        $claims = $service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE);

        $this->assertNotNull($claims);
        $this->assertSame('broker-subject-1', $claims['sub']);

    }//end testAValidTokenPassesEveryCheck()

    public function testAudienceAsAnArrayContainingTheClientIdPasses(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: ['aud' => ['some-other-client', self::CLIENT_ID]]);

        $this->assertNotNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testAudienceAsAnArrayContainingTheClientIdPasses()

    public function testNonceMismatchIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: ['nonce' => 'a-different-nonce']);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testNonceMismatchIsRejected()

    public function testWrongIssuerIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: ['iss' => 'https://not-the-configured-broker.example']);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testWrongIssuerIsRejected()

    public function testWrongAudienceIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: ['aud' => 'a-different-client']);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testWrongAudienceIsRejected()

    public function testExpiredTokenIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: ['iat' => (time() - 7200), 'exp' => (time() - 3600)]);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testExpiredTokenIsRejected()

    public function testATokenIssuedTooFarInTheFutureIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: ['iat' => (time() + 7200), 'exp' => (time() + 10800)]);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testATokenIssuedTooFarInTheFutureIsRejected()

    public function testBadRs256SignatureIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: []);

        // Corrupt the raw signature bytes (not a trailing base64 padding bit):
        // decode the signature segment, flip one bit of a middle byte, and
        // re-encode. Mutating the final base64 character can be a no-op when it
        // only carries base64 padding bits, so operate on the decoded bytes to
        // guarantee the signature actually differs.
        $parts     = explode('.', $token);
        $signature = base64_decode(strtr($parts[2], '-_', '+/').str_repeat('=', ((4 - (strlen($parts[2]) % 4)) % 4)));
        $mid       = intdiv(strlen($signature), 2);
        $signature[$mid] = chr((ord($signature[$mid]) ^ 0x01));
        $parts[2]        = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $tamperedToken   = implode('.', $parts);

        $this->assertNull($service->validateIdTokenAgainstJwks($tamperedToken, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testBadRs256SignatureIsRejected()

    public function testATokenSignedByADifferentKeyIsRejected(): void
    {
        // Signed by a SECOND keypair whose public half is NOT in the JWKS —
        // simulates a forged token / a broker impersonation attempt.
        $otherKeyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $service      = $this->service();
        $token        = $this->signedTokenWithKey($otherKeyPair, claimOverrides: []);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testATokenSignedByADifferentKeyIsRejected()

    public function testAlgNoneIsRejectedEvenWithAnEmptySignature(): void
    {
        $service = $this->service();
        $header  = $this->b64Url((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $claims  = $this->b64Url((string) json_encode($this->claims([])));
        // `alg: none` tokens conventionally carry an EMPTY signature segment.
        $token = $header.'.'.$claims.'.';

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testAlgNoneIsRejectedEvenWithAnEmptySignature()

    public function testHs256AlgorithmConfusionIsRejected(): void
    {
        // A classic algorithm-confusion attempt: HMAC-"sign" using the RSA
        // public modulus as if it were an HS256 secret, and claim `alg:
        // HS256`. The strict RS256 allow-list rejects it on the `alg` check
        // alone — it never reaches signature verification.
        $service       = $this->service();
        $header        = $this->b64Url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $claimsSegment = $this->b64Url((string) json_encode($this->claims([])));
        $fakeSecret    = base64_decode(strtr($this->jwkComponents['n'], '-_', '+/'));
        $signature     = $this->b64Url(hash_hmac('sha256', $header.'.'.$claimsSegment, (string) $fakeSecret, true));
        $token         = $header.'.'.$claimsSegment.'.'.$signature;

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testHs256AlgorithmConfusionIsRejected()

    public function testAnUnknownKidIsRejected(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: [], kid: 'a-kid-not-in-the-jwks');

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testAnUnknownKidIsRejected()

    public function testAmbiguousKeySetWithNoKidHeaderIsRejected(): void
    {
        // No `kid` in the header, and the JWKS carries TWO RSA keys —
        // ambiguous resolution must fail closed, never guess.
        $service    = $this->service();
        $token      = $this->signedToken(claimOverrides: [], kid: null);
        $secondPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $secondJwks = $this->jwks();
        $secondJwks['keys'][] = $this->jwkEntry($secondPair, 'key-2');

        $this->assertNull($service->validateIdTokenAgainstJwks($token, $secondJwks, self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testAmbiguousKeySetWithNoKidHeaderIsRejected()

    public function testNoKidHeaderButExactlyOneRsaKeyInTheJwksStillValidates(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: [], kid: null);

        $this->assertNotNull($service->validateIdTokenAgainstJwks($token, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testNoKidHeaderButExactlyOneRsaKeyInTheJwksStillValidates()

    public function testMalformedTokenShapeIsRejected(): void
    {
        $service = $this->service();

        foreach (['not-a-jwt', 'only.two', 'a.b.c.d', ''] as $malformed) {
            $this->assertNull($service->validateIdTokenAgainstJwks($malformed, $this->jwks(), self::ISSUER, self::CLIENT_ID, self::NONCE), "'{$malformed}' must be rejected");
        }

    }//end testMalformedTokenShapeIsRejected()

    public function testEmptyJwksRejectsEveryToken(): void
    {
        $service = $this->service();
        $token   = $this->signedToken(claimOverrides: []);

        $this->assertNull($service->validateIdTokenAgainstJwks($token, ['keys' => []], self::ISSUER, self::CLIENT_ID, self::NONCE));

    }//end testEmptyJwksRejectsEveryToken()

    // -- discover() / exchangeCode() / verifyIdToken() orchestration --------
    public function testDiscoverFailsClosedOnAnEmptyIssuer(): void
    {
        $service = $this->service();
        $this->assertNull($service->discover(''));

    }//end testDiscoverFailsClosedOnAnEmptyIssuer()

    public function testDiscoverFailsClosedWhenTheDocumentIsMissingARequiredEndpoint(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn((string) json_encode(['authorization_endpoint' => 'https://broker.example/authorize']));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $service = $this->service(clientService: $clientService);

        $this->assertNull($service->discover(self::ISSUER));

    }//end testDiscoverFailsClosedWhenTheDocumentIsMissingARequiredEndpoint()

    public function testVerifyIdTokenRetriesOnceOnAKeyRotationBeforeFailingClosed(): void
    {
        $rotatedPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $token       = $this->signedTokenWithKey($rotatedPair, claimOverrides: [], kid: 'rotated-kid');

        // First (cached) JWKS fetch returns the OLD key set (miss); the
        // service must retry ONCE with a forced refresh, which returns the
        // NEW key set containing the rotated key — and THEN validate.
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $oldJwksResponse = $this->createMock(IResponse::class);
        $oldJwksResponse->method('getBody')->willReturn((string) json_encode($this->jwks()));

        $rotatedEntry    = $this->jwkEntry($rotatedPair, 'rotated-kid');
        $newJwksResponse = $this->createMock(IResponse::class);
        $newJwksResponse->method('getBody')->willReturn((string) json_encode(['keys' => [$rotatedEntry]]));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnOnConsecutiveCalls($oldJwksResponse, $newJwksResponse);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $service = $this->service(clientService: $clientService, cacheFactory: $cacheFactory);
        $claims  = $service->verifyIdToken($token, 'https://broker.example/jwks', self::ISSUER, self::CLIENT_ID, self::NONCE);

        $this->assertNotNull($claims, 'a rotated key must validate after the forced-refresh retry');

    }//end testVerifyIdTokenRetriesOnceOnAKeyRotationBeforeFailingClosed()

    // -- fixtures -------------------------------------------------------------
    private function service(?IClientService $clientService=null, ?ICacheFactory $cacheFactory=null): OidcClientService
    {
        return new OidcClientService(
            ($clientService ?? $this->createMock(IClientService::class)),
            ($cacheFactory ?? $this->stubCacheFactory()),
            $this->createMock(LoggerInterface::class)
        );

    }//end service()

    private function stubCacheFactory(): ICacheFactory
    {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return $cacheFactory;

    }//end stubCacheFactory()

    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    private function jwks(): array
    {
        return ['keys' => [$this->jwkEntry($this->privateKey, self::KID)]];

    }//end jwks()

    /**
     * @param resource|\OpenSSLAsymmetricKey $keyPair
     *
     * @return array<string, mixed>
     */
    private function jwkEntry($keyPair, string $kid): array
    {
        $details = openssl_pkey_get_details($keyPair);

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'n'   => $this->b64Url($details['rsa']['n']),
            'e'   => $this->b64Url($details['rsa']['e']),
        ];

    }//end jwkEntry()

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides): array
    {
        return array_merge(
            [
                'iss'   => self::ISSUER,
                'aud'   => self::CLIENT_ID,
                'nonce' => self::NONCE,
                'sub'   => 'broker-subject-1',
                'iat'   => time(),
                'exp'   => (time() + 3600),
                'acr'   => 'high-loa',
            ],
            $overrides
        );

    }//end claims()

    /**
     * @param array<string, mixed> $claimOverrides
     */
    private function signedToken(array $claimOverrides, ?string $kid=self::KID): string
    {
        return $this->signedTokenWithKey($this->privateKey, $claimOverrides, $kid);

    }//end signedToken()

    /**
     * @param resource|\OpenSSLAsymmetricKey $key
     * @param array<string, mixed>           $claimOverrides
     */
    private function signedTokenWithKey($key, array $claimOverrides, ?string $kid=self::KID): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($kid !== null) {
            $header['kid'] = $kid;
        }

        $headerPart = $this->b64Url((string) json_encode($header));
        $claimsPart = $this->b64Url((string) json_encode($this->claims($claimOverrides)));

        openssl_sign($headerPart.'.'.$claimsPart, $signature, $key, OPENSSL_ALGO_SHA256);

        return $headerPart.'.'.$claimsPart.'.'.$this->b64Url($signature);

    }//end signedTokenWithKey()

    private function b64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    }//end b64Url()
}//end class
