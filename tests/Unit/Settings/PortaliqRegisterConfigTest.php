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
        // portal-session-hardening-v2 (T07) bumped the register's OWN version
        // when it added `portalAuditEntry`; portalAccount's individual schema
        // version is untouched by that change.
        $this->assertSame('0.4.0', self::$register['info']['version']);
        $this->assertSame('0.3.0', self::$register['components']['schemas']['portalAccount']['version']);

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
