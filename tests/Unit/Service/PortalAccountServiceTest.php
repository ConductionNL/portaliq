<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * portal-oidc-broker-login T08: find-or-create `portalAccount` keyed on
 * `(identityType, identityRef, organisation)`. A brand-new identity mints a
 * fresh, cryptographically random subjectRef (or reuses a validated-claim
 * override, never a request parameter); an EXISTING account's OWN subjectRef
 * always wins on a later login, regardless of any override — a stable
 * identity never gets a second, colliding subjectRef.
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T08
 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
 */
class PortalAccountServiceTest extends TestCase
{

    public function testANewIdentityMintsAFreshSubjectRefWhenNoOverrideIsGiven(): void
    {
        [$service] = $this->serviceWithStore();

        $account = $service->findOrCreate(identityType: 'eherkenning', identityRef: 'kvk-1', organisation: 'gemeente-x', audience: 'supplier');

        $this->assertNotNull($account);
        $this->assertTrue($account['isNew']);
        $this->assertNotSame('', $account['subjectRef']);

    }//end testANewIdentityMintsAFreshSubjectRefWhenNoOverrideIsGiven()

    public function testANewIdentityUsesAValidatedClaimOverrideWhenGiven(): void
    {
        [$service] = $this->serviceWithStore();

        $account = $service->findOrCreate(identityType: 'eherkenning', identityRef: 'kvk-1', organisation: 'gemeente-x', audience: 'supplier', subjectRefOverride: 'claim-derived-ref-1');

        $this->assertSame('claim-derived-ref-1', $account['subjectRef']);
        $this->assertTrue($account['isNew']);

    }//end testANewIdentityUsesAValidatedClaimOverrideWhenGiven()

    public function testAReturningIdentityReusesItsOwnExistingSubjectRefRegardlessOfAnyOverride(): void
    {
        [$service] = $this->serviceWithStore();

        $first  = $service->findOrCreate(identityType: 'digid', identityRef: 'bsn-pseudonym-1', organisation: 'gemeente-x', audience: 'client');
        $second = $service->findOrCreate(identityType: 'digid', identityRef: 'bsn-pseudonym-1', organisation: 'gemeente-x', audience: 'client', subjectRefOverride: 'a-different-claim-value');

        $this->assertSame($first['subjectRef'], $second['subjectRef']);
        $this->assertFalse($second['isNew']);

    }//end testAReturningIdentityReusesItsOwnExistingSubjectRefRegardlessOfAnyOverride()

    public function testDifferentOrganisationsGetDistinctAccountsForTheSameIdentityRef(): void
    {
        [$service] = $this->serviceWithStore();

        $orgA = $service->findOrCreate(identityType: 'eherkenning', identityRef: 'kvk-shared', organisation: 'gemeente-a', audience: 'supplier');
        $orgB = $service->findOrCreate(identityType: 'eherkenning', identityRef: 'kvk-shared', organisation: 'gemeente-b', audience: 'supplier');

        $this->assertNotSame($orgA['subjectRef'], $orgB['subjectRef']);

    }//end testDifferentOrganisationsGetDistinctAccountsForTheSameIdentityRef()

    public function testDifferentIdentityTypesGetDistinctAccountsForTheSameIdentityRefValue(): void
    {
        [$service] = $this->serviceWithStore();

        $digid       = $service->findOrCreate(identityType: 'digid', identityRef: 'shared-value', organisation: 'gemeente-x', audience: 'client');
        $eherkenning = $service->findOrCreate(identityType: 'eherkenning', identityRef: 'shared-value', organisation: 'gemeente-x', audience: 'supplier');

        $this->assertNotSame($digid['subjectRef'], $eherkenning['subjectRef']);

    }//end testDifferentIdentityTypesGetDistinctAccountsForTheSameIdentityRefValue()

    public function testMissingRequiredFieldsFailsClosed(): void
    {
        [$service] = $this->serviceWithStore();

        $this->assertNull($service->findOrCreate(identityType: '', identityRef: 'x', organisation: 'o', audience: 'supplier'));
        $this->assertNull($service->findOrCreate(identityType: 'digid', identityRef: '', organisation: 'o', audience: 'supplier'));
        $this->assertNull($service->findOrCreate(identityType: 'digid', identityRef: 'x', organisation: '', audience: 'supplier'));

    }//end testMissingRequiredFieldsFailsClosed()

    public function testAnOpenRegisterWriteFailureFailsClosed(): void
    {
        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readCollection')->willReturn([]);

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturn(null);

        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturn('generated-subject-ref');

        $service = new PortalAccountService($reader, $writer, $random);

        $this->assertNull($service->findOrCreate(identityType: 'digid', identityRef: 'x', organisation: 'o', audience: 'client'));

    }//end testAnOpenRegisterWriteFailureFailsClosed()

    /**
     * @return array{0: PortalAccountService}
     */
    private function serviceWithStore(): array
    {
        $store  = [];
        $random = $this->createMock(ISecureRandom::class);
        $counter = 0;
        $random->method('generate')->willReturnCallback(function () use (&$counter) {
            $counter++;
            return 'generated-subject-ref-'.$counter;
        });

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$store) {
                $uuid         = 'uuid-'.(count($store) + 1);
                $data['uuid'] = $uuid;
                $store[$uuid] = $data;
                return $data;
            }
        );
        $writer->method('updateObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$store) {
                if (isset($store[$id]) === false) {
                    return null;
                }

                $store[$id] = array_merge($store[$id], $data);
                return $store[$id];
            }
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readCollection')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='', int $limit=200, string $scopeClaim='', string $contributingApp='', mixed $via=null, string $audience='', mixed $fields=null, array $filter=[]) use (&$store) {
                $matches = [];
                foreach ($store as $row) {
                    if (($row[$scopeField] ?? null) !== $subjectRef) {
                        continue;
                    }

                    foreach ($filter as $key => $value) {
                        if (($row[$key] ?? null) !== $value) {
                            continue 2;
                        }
                    }

                    $matches[] = $row;
                }

                return $matches;
            }
        );

        return [new PortalAccountService($reader, $writer, $random)];

    }//end serviceWithStore()

}//end class
