<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Guards the contract-v2 register config delta (the WHOLE config change of
 * this slice): portalAccount gains the server-managed `claims` object property
 * with a nil-UUID example, both versions bump to 0.2.0 for the repair-path
 * re-import, `required` stays untouched (union-merge caution, migration.md),
 * and the dev seed accounts carry placeholders only.
 *
 * @spec openspec/changes/contract-v2/tasks.md#T4
 * @spec openspec/changes/contract-v2/tasks.md#T9
 */
class PortaliqRegisterConfigTest extends TestCase
{

    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @var array<string, mixed>
     */
    private static array $register = [];

    public static function setUpBeforeClass(): void
    {
        $json           = (string) file_get_contents(__DIR__.'/../../../lib/Settings/portaliq_register.json');
        self::$register = (array) json_decode($json, true);

    }//end setUpBeforeClass()

    public function testRegisterJsonParsesAndVersionsAreBumped(): void
    {
        $this->assertNotSame([], self::$register, 'register JSON must parse');
        // 0.12.0: declared `components.registers.portaliq` — the register
        // itself. OpenRegister's ImportHandler creates a Register row from that
        // key and nowhere else on the main/beta lines, so until now a clean
        // install created 9 schemas and ZERO registers and every
        // GET /api/objects/portaliq/<schema> answered HTTP 404
        // "Register not found: 'portaliq'". The version bump is load-bearing:
        // importFromApp is version-gated, so a frozen info.version would never
        // re-import and the register would stay absent on existing installs.
        // 0.11.0 (portalSession 0.3.0): declared `authTime` on portalSession.
        // PortalSessionService::mintSession() has always written it, but the
        // schema never described it, so OpenRegister's MagicMapper discarded it
        // on EVERY session mint ("Discarding 1 property the schema \"Portal
        // Session\" does not declare: authTime") and the stored row silently
        // lost the origin-login timestamp. Additive.
        // 0.10.0 (portalPage 0.2.0): re-seeded the SUPPLIER demo portalPage that
        // portal-page-provisioning deleted without carrying over (only the
        // citizen page shipped, so a fresh install contributed nothing to a
        // supplier), and declared the contract-v3 vocabulary the normaliser
        // already consumes but the schema never described — `label`/`kind`/
        // `rowActions`/`defaultSort`/`filesUpload`/`filesDownload` on
        // collections, `submitLabel`/`successMessage`/`fieldConfigs`/
        // `optionsProviders` on actions, and the contribution-level
        // `notifications` opt-in. All additive.
        // 0.9.0: renamed portalAuditEntry's `id` property to `targetId` so it no
        // longer collides with OpenRegister's reserved object-id key (which made
        // every append-only audit write fail). 0.8.0 added the `portalPage` schema
        // (data-provisioned portal contributions, ADR-046). Both additive.
        $this->assertSame('0.12.0', self::$register['info']['version']);
        $this->assertSame('0.5.0', self::$register['components']['schemas']['portalAccount']['version']);
        $this->assertSame('0.2.0', self::$register['components']['schemas']['portalPage']['version']);
        $this->assertSame('0.3.0', self::$register['components']['schemas']['portalSession']['version']);

    }//end testRegisterJsonParsesAndVersionsAreBumped()


    /**
     * The register must declare ITSELF, listing exactly its own schemas.
     *
     * OpenRegister's ImportHandler creates a Register row only from
     * `components.registers` (ImportHandler.php:1514) on the main/beta lines.
     * Without it a clean install provisions the schemas and no register, the
     * import still reports success, and every object route 404s — a silent
     * outage this test exists to make loud.
     *
     * The schema list is asserted to be the EXACT set of schema SLUGS, not
     * components.schemas keys: ImportHandler keys its schemasMap by
     * $schema->getSlug(), so a register listing the keys binds ZERO schemas
     * while still looking correctly declared. A register listing only SOME
     * schemas is the same silent partial outage, one schema at a time.
     */
    public function testRegisterDeclaresItselfWithExactlyItsOwnSchemaSlugs(): void
    {
        $registers = (self::$register['components']['registers'] ?? []);
        $this->assertArrayHasKey('portaliq', $registers, 'the portaliq register must be declared');

        $declared = $registers['portaliq'];
        $this->assertSame('portaliq', $declared['slug']);
        $this->assertSame(
            self::$register['info']['version'],
            $declared['version'],
            'the register version must track info.version, or a later schema change never re-imports'
        );

        $expected = [];
        foreach ((self::$register['components']['schemas'] ?? []) as $key => $schema) {
            $expected[] = ($schema['slug'] ?? $key);
        }

        sort($expected);
        $actual = $declared['schemas'];
        sort($actual);

        $this->assertSame($expected, $actual, 'the register must list exactly its own schema slugs');

    }//end testRegisterDeclaresItselfWithExactlyItsOwnSchemaSlugs()

    /**
     * Every property `PortalSessionService::mintSession()` writes MUST be
     * declared on the portalSession schema. An undeclared key is not an error
     * anywhere in the stack — OpenRegister's MagicMapper drops it with a log
     * line and the write still returns success — so this is the only place the
     * loss is detectable. Regression guard for the discarded `authTime`.
     *
     * @return void
     */
    public function testPortalSessionDeclaresEveryPropertyTheMinterWrites(): void
    {
        $declared = array_keys(
            (array) self::$register['components']['schemas']['portalSession']['properties']
        );

        // The literal payload of PortalSessionService::mintSession()'s
        // createObject() call. Keep in step with it.
        $written = [
            'subjectRef',
            'audience',
            'organisation',
            'jti',
            'trustLevel',
            'issuedAt',
            'expiresAt',
            'revoked',
            'authTime',
        ];

        foreach ($written as $property) {
            $this->assertContains(
                $property,
                $declared,
                "portalSession must declare `$property` — mintSession() writes it, and MagicMapper silently discards anything the schema does not declare."
            );
        }

    }//end testPortalSessionDeclaresEveryPropertyTheMinterWrites()

    /**
     * The `audience` property on portalAccount and portalSession MUST be an
     * open string (no enum) so contract-v2 audiences (citizen, student, parent,
     * …) can be persisted without a schema change. Regression guard for #20:
     * the closed [supplier, client] enum blocked every new-audience account at
     * the data layer.
     *
     * @return void
     */
    public function testAudienceIsOpenStringNotEnumConstrained(): void
    {
        foreach (['portalAccount', 'portalSession'] as $slug) {
            $audience = self::$register['components']['schemas'][$slug]['properties']['audience'];
            $this->assertSame('string', $audience['type']);
            $this->assertArrayNotHasKey('enum', $audience, "$slug.audience must not be enum-constrained (#20)");
        }

    }//end testAudienceIsOpenStringNotEnumConstrained()

    public function testPortalAccountClaimsPropertyIsServerManagedShape(): void
    {
        $account = self::$register['components']['schemas']['portalAccount'];

        $claims = $account['properties']['claims'];
        $this->assertSame('object', $claims['type']);
        // The documented example demonstrates {appId: {claimName: uuid}} with a
        // nil UUID — placeholders only, never a real identifier.
        $this->assertSame(self::NIL_UUID, $claims['example']['pipelinq']['linkedContactId']);

        // The claim map lives on a never-public schema (Risk 3 in proposal.md).
        $this->assertFalse($account['x-openregister']['publicRead']);
        $this->assertFalse($account['x-openregister']['publicWrite']);

    }//end testPortalAccountClaimsPropertyIsServerManagedShape()

    public function testPortalAccountRequiredListIsUnchanged(): void
    {
        // Union-merge caution (migration.md): the additive property must not
        // touch the required list — claims stays OPTIONAL.
        $this->assertSame(
            ['audience', 'subjectRef', 'organisation'],
            self::$register['components']['schemas']['portalAccount']['required']
        );

    }//end testPortalAccountRequiredListIsUnchanged()

    /**
     * portal-notifications-dispatch T01: the new `portalNotification` log
     * schema is never publicly readable/writable, and `portalAccount` gains the
     * optional `needsAlternativeContact` fallback flag (WMEBV notificatieplicht,
     * ~Awb 2:11) without touching the `required` list.
     */
    public function testPortalNotificationSchemaIsAddedAndNeverPublic(): void
    {
        $schemas = self::$register['components']['schemas'];
        $this->assertArrayHasKey('portalNotification', $schemas, 'portalNotification schema must exist');

        $notification = $schemas['portalNotification'];
        $this->assertFalse($notification['x-openregister']['publicRead']);
        $this->assertFalse($notification['x-openregister']['publicWrite']);
        $this->assertSame(
            ['accountRef', 'ruleKey', 'channel', 'status', 'attempts', 'lastAttemptAt'],
            $notification['required']
        );
        $this->assertSame(['email'], $notification['properties']['channel']['enum']);
        $this->assertSame(['sent', 'failed'], $notification['properties']['status']['enum']);

        $account = $schemas['portalAccount'];
        $this->assertSame('boolean', $account['properties']['needsAlternativeContact']['type']);
        // Union-merge caution (migration.md): the additive property must not
        // touch the required list — needsAlternativeContact stays OPTIONAL.
        $this->assertSame(['audience', 'subjectRef', 'organisation'], $account['required']);

    }//end testPortalNotificationSchemaIsAddedAndNeverPublic()

    /**
     * portal-oidc-broker-login T01/T08: `portalAccount.identityType` gains the
     * additive `generic` enum member (a broker-agnostic OIDC provider preset
     * for a broker that is none of digid/eherkenning/eidas), and the new
     * `portalOidcState` schema (single-use start→callback state/nonce/PKCE
     * storage) is never publicly readable/writable and never touches
     * `portalAccount`'s `required` list.
     */
    public function testPortalOidcStateSchemaIsAddedAndNeverPublic(): void
    {
        $schemas = self::$register['components']['schemas'];
        $this->assertArrayHasKey('portalOidcState', $schemas, 'portalOidcState schema must exist');

        $state = $schemas['portalOidcState'];
        $this->assertFalse($state['x-openregister']['publicRead']);
        $this->assertFalse($state['x-openregister']['publicWrite']);
        $this->assertSame(
            ['state', 'nonce', 'codeVerifier', 'org', 'provider', 'expiresAt'],
            $state['required']
        );

        $account = $schemas['portalAccount'];
        $this->assertSame(
            ['eherkenning', 'digid', 'eidas', 'generic', 'dev'],
            $account['properties']['identityType']['enum']
        );
        // Union-merge caution (migration.md): the additive enum member must not
        // touch the required list.
        $this->assertSame(['audience', 'subjectRef', 'organisation'], $account['required']);

    }//end testPortalOidcStateSchemaIsAddedAndNeverPublic()

    public function testSeedAccountsUsePlaceholdersAndProveBothClaimStates(): void
    {
        $objects  = (array) (self::$register['components']['objects'] ?? []);
        $accounts = [];
        foreach ($objects as $object) {
            if ((($object['@self']['schema'] ?? '') === 'portalAccount') === true) {
                $accounts[$object['@self']['slug']] = $object;
            }
        }

        $this->assertArrayHasKey('dev-supplier-account', $accounts);
        $this->assertArrayHasKey('dev-client-account', $accounts);
        $this->assertArrayHasKey('second-supplier-account', $accounts);

        // Object 1 proves the claim shape end-to-end (nil UUID only)...
        $supplier = $accounts['dev-supplier-account'];
        $this->assertSame(self::NIL_UUID, $supplier['claims']['portaliq']['exampleContactId']);
        $this->assertSame('dev-supplier', $supplier['subjectRef']);

        // ...and Object 2 proves the fail-closed-empty path (no claims).
        $this->assertSame([], $accounts['dev-client-account']['claims']);

        // Placeholders only: every seeded identityRef screams EXAMPLE.
        foreach ($accounts as $slug => $account) {
            $this->assertStringStartsWith('EXAMPLE_', (string) $account['identityRef'], "seed '{$slug}' must use placeholder identityRef");
        }

    }//end testSeedAccountsUsePlaceholdersAndProveBothClaimStates()

}//end class
