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
        // 0.9.0: renamed portalAuditEntry's `id` property to `targetId` so it no
        // longer collides with OpenRegister's reserved object-id key (which made
        // every append-only audit write fail). 0.8.0 added the `portalPage` schema
        // (data-provisioned portal contributions, ADR-046). Both additive.
        $this->assertSame('0.9.0', self::$register['info']['version']);
        $this->assertSame('0.5.0', self::$register['components']['schemas']['portalAccount']['version']);
        $this->assertSame('0.1.0', self::$register['components']['schemas']['portalPage']['version']);

    }//end testRegisterJsonParsesAndVersionsAreBumped()

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
