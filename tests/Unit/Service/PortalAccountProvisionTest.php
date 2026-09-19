<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-space: an account exists before its owner ever logs in.
 * Provisioning refuses a call it could never match again, a pending account
 * is activated by the login that matches it and by no other, and an app's
 * claim is written under the dispatching app's own id.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountProvisionTest extends TestCase {

	/**
	 * The rows the fake OpenRegister store holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	public function testProvisioningOnAnIdentityReferenceCreatesOnePendingAccount(): void {
		$service = $this->service();

		$account = $service->provision(
			audience: 'client',
			organisation: 'gemeente-x',
			identityType: 'digid',
			identityRef: 'bsn-pseudonym-1',
			provisionedBy: 'clerk-anna'
		);

		$this->assertNotNull($account);
		$this->assertTrue($account['isNew']);
		$this->assertSame('pending', $account['status']);

		$row = $this->onlyRow();
		$this->assertSame('pending', $row['status']);
		$this->assertSame('clerk-anna', $row['provisionedBy']);
		$this->assertNotSame('', (string)$row['provisionedAt']);

	}//end testProvisioningOnAnIdentityReferenceCreatesOnePendingAccount()

	public function testProvisioningTwiceOnTheSameIdentityReturnsTheSameAccount(): void {
		$service = $this->service();

		$first = $service->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');
		$second = $service->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		$this->assertSame($first['subjectRef'], $second['subjectRef']);
		$this->assertFalse($second['isNew']);
		$this->assertCount(1, $this->store);

	}//end testProvisioningTwiceOnTheSameIdentityReturnsTheSameAccount()

	public function testProvisioningWithNeitherReferenceNorEmailIsRefused(): void {
		$service = $this->service();

		$this->assertNull($service->provision(audience: 'client', organisation: 'gemeente-x'));
		$this->assertNull($service->provision(audience: '', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1'));
		$this->assertNull($service->provision(audience: 'client', organisation: '', identityType: 'digid', identityRef: 'bsn-1'));
		$this->assertSame([], $this->store);

	}//end testProvisioningWithNeitherReferenceNorEmailIsRefused()

	public function testTheProvisionedCitizensFirstLoginActivatesTheirOwnAccount(): void {
		$service = $this->service();

		$provisioned = $service->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-X');
		$login = $service->findOrCreate(identityType: 'digid', identityRef: 'bsn-X', organisation: 'gemeente-x', audience: 'client');

		$this->assertSame($provisioned['subjectRef'], $login['subjectRef']);
		$this->assertFalse($login['isNew']);
		$this->assertCount(1, $this->store);
		$this->assertSame('active', $this->onlyRow()['status']);

	}//end testTheProvisionedCitizensFirstLoginActivatesTheirOwnAccount()

	public function testADifferentPersonDoesNotInheritThePendingAccount(): void {
		$service = $this->service();

		$provisioned = $service->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-X');
		$other = $service->findOrCreate(identityType: 'digid', identityRef: 'bsn-Y', organisation: 'gemeente-x', audience: 'client');

		$this->assertNotSame($provisioned['subjectRef'], $other['subjectRef']);
		$this->assertCount(2, $this->store);
		$this->assertSame('pending', $this->rowBySubjectRef($provisioned['subjectRef'])['status']);

	}//end testADifferentPersonDoesNotInheritThePendingAccount()

	public function testAVerifiedEmailMatchesAnEmailOnlyPendingAccount(): void {
		$service = $this->service();

		$provisioned = $service->provision(audience: 'client', organisation: 'gemeente-x', email: 'ans@example.org', verifiedEmail: true);
		$login = $service->findOrCreate(
			identityType: 'digid',
			identityRef: 'bsn-Z',
			organisation: 'gemeente-x',
			audience: 'client',
			verifiedEmail: 'ans@example.org'
		);

		$this->assertSame($provisioned['subjectRef'], $login['subjectRef']);
		$this->assertCount(1, $this->store);
		$this->assertSame('active', $this->onlyRow()['status']);
		$this->assertSame('bsn-Z', $this->onlyRow()['identityRef']);

	}//end testAVerifiedEmailMatchesAnEmailOnlyPendingAccount()

	public function testAnUnverifiedAddressMatchesNothing(): void {
		$service = $this->service();

		$provisioned = $service->provision(audience: 'client', organisation: 'gemeente-x', email: 'ans@example.org', verifiedEmail: false);
		$login = $service->findOrCreate(
			identityType: 'digid',
			identityRef: 'bsn-Z',
			organisation: 'gemeente-x',
			audience: 'client',
			verifiedEmail: 'ans@example.org'
		);

		$this->assertNotSame($provisioned['subjectRef'], $login['subjectRef']);
		$this->assertCount(2, $this->store);

	}//end testAnUnverifiedAddressMatchesNothing()

	public function testAnAddressNeverClaimsAnAccountProvisionedOnAnIdentityReference(): void {
		$service = $this->service();

		$provisioned = $service->provision(
			audience: 'client',
			organisation: 'gemeente-x',
			identityType: 'digid',
			identityRef: 'bsn-X',
			email: 'ans@example.org',
			verifiedEmail: true
		);
		$login = $service->findOrCreate(
			identityType: 'digid',
			identityRef: 'bsn-SOMEONE-ELSE',
			organisation: 'gemeente-x',
			audience: 'client',
			verifiedEmail: 'ans@example.org'
		);

		$this->assertNotSame($provisioned['subjectRef'], $login['subjectRef']);
		$this->assertSame('pending', $this->rowBySubjectRef($provisioned['subjectRef'])['status']);

	}//end testAnAddressNeverClaimsAnAccountProvisionedOnAnIdentityReference()

	public function testAClaimIsWrittenUnderTheDispatchingAppsOwnId(): void {
		$service = $this->service();

		$account = $service->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-X');
		$written = $service->claim(subjectRef: $account['subjectRef'], appId: 'dossiq', claimName: 'linkedRequesterId', value: 'requester-77');

		$this->assertTrue($written);
		$this->assertSame(['dossiq' => ['linkedRequesterId' => 'requester-77']], $this->onlyRow()['claims']);

	}//end testAClaimIsWrittenUnderTheDispatchingAppsOwnId()

	public function testAClaimOnAnUnknownAccountIsRefused(): void {
		$service = $this->service();

		$this->assertFalse($service->claim(subjectRef: 'no-such-subject', appId: 'dossiq', claimName: 'linkedRequesterId', value: 'x'));
		$this->assertFalse($service->claim(subjectRef: 'x', appId: '', claimName: 'linkedRequesterId', value: 'x'));

	}//end testAClaimOnAnUnknownAccountIsRefused()

	public function testAVoidedPendingAccountNeverMatchesALoginAgain(): void {
		$service = $this->service();

		$provisioned = $service->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-X');
		$this->assertTrue($service->voidPending(subjectRef: $provisioned['subjectRef'], reason: 'Provisioned on a mistyped BSN', voidedBy: 'clerk-anna'));

		$login = $service->findOrCreate(identityType: 'digid', identityRef: 'bsn-X', organisation: 'gemeente-x', audience: 'client');

		$this->assertNotSame($provisioned['subjectRef'], $login['subjectRef']);
		$this->assertSame('void', $this->rowBySubjectRef($provisioned['subjectRef'])['status']);
		$this->assertSame('Provisioned on a mistyped BSN', $this->rowBySubjectRef($provisioned['subjectRef'])['voidReason']);

	}//end testAVoidedPendingAccountNeverMatchesALoginAgain()

	public function testAnActiveAccountCannotBeVoided(): void {
		$service = $this->service();

		$account = $service->findOrCreate(identityType: 'digid', identityRef: 'bsn-live', organisation: 'gemeente-x', audience: 'client');

		$this->assertFalse($service->voidPending(subjectRef: $account['subjectRef'], reason: 'because'));
		$this->assertFalse($service->voidPending(subjectRef: $account['subjectRef'], reason: ''));

	}//end testAnActiveAccountCannotBeVoided()

	/**
	 * The row in the store, when there is exactly one.
	 *
	 * @return array<string, mixed>
	 */
	private function onlyRow(): array {
		$this->assertCount(1, $this->store);
		return array_values($this->store)[0];
	}//end onlyRow()

	/**
	 * The stored row carrying this subject reference.
	 *
	 * @param string $subjectRef The subject reference.
	 *
	 * @return array<string, mixed>
	 */
	private function rowBySubjectRef(string $subjectRef): array {
		foreach ($this->store as $row) {
			if (($row['subjectRef'] ?? '') === $subjectRef) {
				return $row;
			}
		}

		$this->fail('No row for subjectRef ' . $subjectRef);
	}//end rowBySubjectRef()

	/**
	 * The service over a fake OpenRegister store that answers reads and writes
	 * the way the real reader and writer do for this schema.
	 *
	 * @return PortalAccountService
	 */
	private function service(): PortalAccountService {
		$this->store = [];
		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function () use (&$counter): string {
				$counter++;
				return 'generated-subject-ref-' . $counter;
			}
		);

		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject'])
			->getMock();
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$uuid = 'uuid-' . (count($this->store) + 1);
				$data['uuid'] = $uuid;
				$this->store[$uuid] = $data;
				return $data;
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): ?array {
				if (isset($this->store[$id]) === false) {
					return null;
				}

				$this->store[$id] = array_merge($this->store[$id], $data);
				return $this->store[$id];
			}
		);

		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []): array {
				$matches = [];
				foreach ($this->store as $row) {
					if (($row[$scopeField] ?? null) !== $subjectRef) {
						continue;
					}

					if ($organisation !== '' && ($row['organisation'] ?? null) !== $organisation) {
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

		return new PortalAccountService($reader, $writer, $random);
	}//end service()

}//end class
