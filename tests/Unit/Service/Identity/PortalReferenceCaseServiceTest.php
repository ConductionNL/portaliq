<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Identity\PortalReferenceCaseService;
use PHPUnit\Framework\TestCase;

/**
 * portaliq#796: a case number and an address open a case only when the
 * address is the one recorded on that case, and a reference session reads
 * exactly the one row whose declared reference field equals its claim.
 *
 * The case app's declaration is the only source of both field names; a
 * collection that declares no address field opens nothing.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-ways-in-screens/design.md
 */
class PortalReferenceCaseServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];
		$this->seedRow('case', ['identifier' => 'Z-2026-0042', 'applicantEmail' => 'Anna@Example.nl', 'organisation' => 'gemeente-x', 'status' => 'in behandeling']);
		$this->seedRow('case', ['identifier' => 'Z-2026-0043', 'applicantEmail' => 'piet@example.nl', 'organisation' => 'gemeente-x', 'status' => 'ontvangen']);
		$this->seedRow('portalFormBinding', ['portal' => 'gemeente-x', 'status' => 'draft', 'typeRegister' => 'dossiq', 'typeSchema' => 'caseType', 'typeId' => 'melding', 'caseRegister' => 'elders', 'caseSchema' => 'case']);
		$this->seedRow('portalFormBinding', ['portal' => 'gemeente-x', 'status' => 'published', 'typeRegister' => 'dossiq', 'typeSchema' => 'caseType', 'typeId' => 'melding', 'caseRegister' => 'dossiq', 'caseSchema' => 'case']);

	}//end setUp()

	public function testTheRecordedAddressOpensTheCase(): void {
		$found = $this->service()->linkableCase(
			portal: 'gemeente-x',
			register: 'dossiq',
			schema: 'caseType',
			caseType: 'melding',
			caseReference: 'Z-2026-0042',
			email: ' anna@example.nl ',
			organisation: 'gemeente-x'
		);

		$this->assertSame(['register' => 'dossiq', 'schema' => 'case'], $found);

	}//end testTheRecordedAddressOpensTheCase()

	public function testAnotherAddressOpensNothing(): void {
		$found = $this->service()->linkableCase(
			portal: 'gemeente-x',
			register: 'dossiq',
			schema: 'caseType',
			caseType: 'melding',
			caseReference: 'Z-2026-0042',
			email: 'piet@example.nl',
			organisation: 'gemeente-x'
		);

		$this->assertNull($found);

	}//end testAnotherAddressOpensNothing()

	public function testAnUnknownCaseNumberOpensNothing(): void {
		$this->assertNull($this->service()->linkableCase(portal: 'gemeente-x', register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-9999', email: 'anna@example.nl', organisation: 'gemeente-x'));

	}//end testAnUnknownCaseNumberOpensNothing()

	public function testACollectionWithoutAnAddressFieldOpensNothing(): void {
		$service = $this->service(collection: ['register' => 'dossiq', 'schema' => 'case', 'referenceField' => 'identifier']);

		$this->assertNull($service->linkableCase(portal: 'gemeente-x', register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-0042', email: 'anna@example.nl', organisation: 'gemeente-x'));

	}//end testACollectionWithoutAnAddressFieldOpensNothing()

	public function testTwoCasesOnOneNumberOpenNeither(): void {
		$this->seedRow('case', ['identifier' => 'Z-2026-0042', 'applicantEmail' => 'anna@example.nl', 'organisation' => 'gemeente-x']);

		$this->assertNull($this->service()->linkableCase(portal: 'gemeente-x', register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-0042', email: 'anna@example.nl', organisation: 'gemeente-x'));

	}//end testTwoCasesOnOneNumberOpenNeither()

	public function testACaseTypeWithoutAPublishedBindingOpensNothing(): void {
		$this->assertNull($this->service()->linkableCase(portal: 'gemeente-x', register: 'dossiq', schema: 'caseType', caseType: 'vergunning', caseReference: 'Z-2026-0042', email: 'anna@example.nl', organisation: 'gemeente-x'));
		$this->assertNull($this->service()->linkableCase(portal: 'gemeente-y', register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-0042', email: 'anna@example.nl', organisation: 'gemeente-x'));

	}//end testACaseTypeWithoutAPublishedBindingOpensNothing()

	public function testAReferenceSessionReadsItsOneCaseAndNoOther(): void {
		$service = $this->service();

		$case = $service->read(reference: ['caseReference' => 'Z-2026-0042', 'organisation' => 'gemeente-x', 'register' => 'dossiq', 'schema' => 'case']);

		$this->assertSame('Z-2026-0042', $case['identifier']);
		$this->assertNull($service->read(reference: ['caseReference' => '', 'organisation' => 'gemeente-x', 'register' => 'dossiq', 'schema' => 'case']));
		$this->assertNull($service->read(reference: ['caseReference' => 'Z-2026-0042', 'organisation' => 'gemeente-y', 'register' => 'dossiq', 'schema' => 'case']));

	}//end testAReferenceSessionReadsItsOneCaseAndNoOther()

	/**
	 * The service over the fake store and a registry double.
	 *
	 * @param array<string, mixed>|null $collection The declared case collection.
	 *
	 * @return PortalReferenceCaseService
	 */
	private function service(
		?array $collection = ['register' => 'dossiq', 'schema' => 'case', 'referenceField' => 'identifier', 'referenceAddressField' => 'applicantEmail'],
	): PortalReferenceCaseService {

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['aggregateFor'])
			->getMock();
		$registry->method('aggregateFor')->willReturn([
			'contributions' => [
				['app' => 'dossiq', 'collections' => [['register' => 'dossiq', 'schema' => 'other'], $collection]],
			],
		]);

		return new PortalReferenceCaseService($registry, $this->fakeReader());
	}//end service()

}//end class
