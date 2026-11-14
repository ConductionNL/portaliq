<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-space: the account lookup re-checks every scope field the
 * store narrowed on, in memory, so a row the query over-returned can never be
 * handed back. A void account never matches a login, a citizen number matches
 * only an active DigiD/eIDAS account, and a verified-email claim reaches only
 * a pending, identity-less row.
 *
 * @covers \OCA\Portaliq\Service\Identity\PortalAccountLookup
 *
 * @spec openspec/specs/portal-identity-space/spec.md
 */
class PortalAccountLookupTest extends TestCase {

	/**
	 * The rows the fake reader answers with, and the calls it recorded.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every readCollection call's arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->calls = [];

	}//end setUp()

	/**
	 * A reader that answers with the configured rows and records its calls.
	 *
	 * @return ScopedCollectionReaderInterface
	 */
	private function reader(): ScopedCollectionReaderInterface {
		$rows = &$this->rows;
		$calls = &$this->calls;

		return new class ($rows, $calls) implements ScopedCollectionReaderInterface {

			/**
			 * @param array<int, array<string, mixed>> $rows  The rows to answer with.
			 * @param array<int, array<string, mixed>> $calls The recorded calls.
			 */
			public function __construct(private array &$rows, private array &$calls) {
			}

			public function readCollection(
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $organisation = '',
				int $limit = 200,
				string $scopeClaim = '',
				string $contributingApp = '',
				mixed $via = null,
				string $audience = '',
				mixed $fields = null,
				array $filter = [],
			): array {
				$this->calls[] = [
					'scopeField' => $scopeField,
					'subjectRef' => $subjectRef,
					'organisation' => $organisation,
					'limit' => $limit,
					'filter' => $filter,
				];

				return $this->rows;
			}
		};

	}//end reader()

	public function testBySubjectRefReturnsTheRowThatReallyCarriesTheReference(): void {
		$this->rows = [
			['subjectRef' => 'other', 'id' => 'wrong'],
			['subjectRef' => 'sub-1', 'id' => 'right'],
		];
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$row = $lookup->bySubjectRef(subjectRef: 'sub-1');

		$this->assertSame('right', $row['id']);
		$this->assertSame('subjectRef', $this->calls[0]['scopeField']);
		$this->assertSame(5, $this->calls[0]['limit']);
		// No organisation is passed: a subjectRef is unique instance-wide.
		$this->assertSame('', $this->calls[0]['organisation']);

	}//end testBySubjectRefReturnsTheRowThatReallyCarriesTheReference()

	public function testBySubjectRefAsksNothingForAnEmptyReference(): void {
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$this->assertNull($lookup->bySubjectRef(subjectRef: ''));
		$this->assertSame([], $this->calls);

	}//end testBySubjectRefAsksNothingForAnEmptyReference()

	public function testByIdentityReverifiesAllThreeFieldsAndSkipsAVoidAccount(): void {
		$this->rows = [
			// Right ref, but a different tenant: over-returned, must be skipped.
			['identityType' => 'digid', 'identityRef' => 'ref-1', 'organisation' => 'other', 'id' => 'wrong-org'],
			// Right everything, but withdrawn.
			['identityType' => 'digid', 'identityRef' => 'ref-1', 'organisation' => 'acme', 'status' => 'void', 'id' => 'void'],
			['identityType' => 'digid', 'identityRef' => 'ref-1', 'organisation' => 'acme', 'status' => 'active', 'id' => 'right'],
		];
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$row = $lookup->byIdentity(identityType: 'digid', identityRef: 'ref-1', organisation: 'acme');

		$this->assertSame('right', $row['id']);
		$this->assertSame(['identityType' => 'digid'], $this->calls[0]['filter']);

	}//end testByIdentityReverifiesAllThreeFieldsAndSkipsAVoidAccount()

	public function testByIdentityReturnsNullWhenNoRowMatchesEveryField(): void {
		$this->rows = [['identityType' => 'eidas', 'identityRef' => 'ref-1', 'organisation' => 'acme']];
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$this->assertNull($lookup->byIdentity(identityType: 'digid', identityRef: 'ref-1', organisation: 'acme'));

	}//end testByIdentityReturnsNullWhenNoRowMatchesEveryField()

	public function testActiveByCitizenNumberMatchesOnlyAnActiveDigidOrEidasRow(): void {
		$this->rows = [
			['identityRef' => '123456782', 'identityType' => 'email', 'status' => 'active', 'id' => 'wrong-type'],
			['identityRef' => '123456782', 'identityType' => 'digid', 'status' => 'pending', 'id' => 'not-active'],
			['identityRef' => '123456782', 'identityType' => 'eidas', 'status' => 'active', 'id' => 'right'],
		];
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$row = $lookup->activeByCitizenNumber(bsn: ' 123456782 ');

		$this->assertSame('right', $row['id']);

	}//end testActiveByCitizenNumberMatchesOnlyAnActiveDigidOrEidasRow()

	public function testActiveByCitizenNumberAsksNothingForAnEmptyNumber(): void {
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$this->assertNull($lookup->activeByCitizenNumber(bsn: '   '));
		$this->assertSame([], $this->calls);

	}//end testActiveByCitizenNumberAsksNothingForAnEmptyNumber()

	public function testPendingByVerifiedEmailReachesOnlyAPendingIdentitylessVerifiedRow(): void {
		$this->rows = [
			// Not verified.
			['email' => 'ann@example.org', 'organisation' => 'acme', 'status' => 'pending', 'verifiedEmail' => false, 'identityRef' => '', 'id' => 'unverified'],
			// Already bound to an identity.
			['email' => 'ann@example.org', 'organisation' => 'acme', 'status' => 'pending', 'verifiedEmail' => true, 'identityRef' => 'ref', 'id' => 'bound'],
			['email' => 'Ann@Example.org', 'organisation' => 'acme', 'status' => 'pending', 'verifiedEmail' => true, 'identityRef' => '', 'id' => 'right'],
		];
		$lookup = new PortalAccountLookup(reader: $this->reader());

		// Asked with a differently-cased address: the match ignores case.
		$row = $lookup->pendingByVerifiedEmail(email: 'ANN@example.org', organisation: 'acme');

		$this->assertSame('right', $row['id']);

	}//end testPendingByVerifiedEmailReachesOnlyAPendingIdentitylessVerifiedRow()

	public function testPendingByVerifiedEmailAsksNothingWithoutEmailOrOrganisation(): void {
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$this->assertNull($lookup->pendingByVerifiedEmail(email: '', organisation: 'acme'));
		$this->assertNull($lookup->pendingByVerifiedEmail(email: 'a@b.nl', organisation: ''));
		$this->assertSame([], $this->calls);

	}//end testPendingByVerifiedEmailAsksNothingWithoutEmailOrOrganisation()

	public function testIdentifierOfReadsTheIdWhereverOpenRegisterPutIt(): void {
		$lookup = new PortalAccountLookup(reader: $this->reader());

		$this->assertSame('flat-uuid', $lookup->identifierOf(['uuid' => 'flat-uuid']));
		$this->assertSame('flat-id', $lookup->identifierOf(['id' => 'flat-id']));
		$this->assertSame('self-uuid', $lookup->identifierOf(['@self' => ['uuid' => 'self-uuid']]));
		$this->assertSame('7', $lookup->identifierOf(['@self' => ['id' => 7]]));
		$this->assertNull($lookup->identifierOf(['email' => 'a@b.nl']));
		// An empty string is no identifier.
		$this->assertNull($lookup->identifierOf(['uuid' => '']));

	}//end testIdentifierOfReadsTheIdWhereverOpenRegisterPutIt()
}//end class
