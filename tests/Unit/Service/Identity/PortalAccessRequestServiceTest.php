<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-007: asking for access
 * is recorded, the owner sees who asked and what for, and the answer reaches
 * the asker without anything new becoming visible to them.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalAccessRequestServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testTheOwnerSeesWhoAskedAndWhatFor(): void {
		$service = $this->service();
		$service->request(subjectRef: 'subject-1', organisation: 'gemeente-x', onBehalfOf: 'kvk-1', reason: 'Ik ben de nieuwe administrateur.', displayName: 'Ans de Vries');

		$pending = $service->forOwner(organisation: 'gemeente-x');

		$this->assertCount(1, $pending);
		$this->assertSame('Ans de Vries', $pending[0]['displayName']);
		$this->assertSame('Ik ben de nieuwe administrateur.', $pending[0]['reason']);
		$this->assertSame('pending', $pending[0]['state']);

	}//end testTheOwnerSeesWhoAskedAndWhatFor()

	public function testARequestWithNoReasonIsRefused(): void {
		$service = $this->service();

		$this->assertNull($service->request(subjectRef: 'subject-1', organisation: 'gemeente-x', reason: '   '));
		$this->assertNull($service->request(subjectRef: '', organisation: 'gemeente-x', reason: 'omdat'));
		$this->assertSame([], $this->storedRows('portalAccessRequest'));

	}//end testARequestWithNoReasonIsRefused()

	public function testARefusalReachesTheAskerAndGrantsNothing(): void {
		$service = $this->service();
		$recorded = $service->request(subjectRef: 'subject-1', organisation: 'gemeente-x', reason: 'omdat');

		$this->assertTrue($service->decide(id: (string)$recorded['uuid'], organisation: 'gemeente-x', granted: false, decidedBy: 'clerk-anna'));

		$mine = $service->madeBy(subjectRef: 'subject-1');
		$this->assertSame('refused', $mine[0]['state']);
		$this->assertSame('clerk-anna', $mine[0]['decidedBy']);
		// A decision is a decision, not a mandate: nothing new was written.
		$this->assertSame([], $this->storedRows('portalMandate'));

	}//end testARefusalReachesTheAskerAndGrantsNothing()

	public function testAnotherTenantCannotAnswerTheRequest(): void {
		$service = $this->service();
		$recorded = $service->request(subjectRef: 'subject-1', organisation: 'gemeente-x', reason: 'omdat');

		$this->assertFalse($service->decide(id: (string)$recorded['uuid'], organisation: 'gemeente-y', granted: true, decidedBy: 'clerk-bob'));
		$this->assertSame('pending', $service->madeBy(subjectRef: 'subject-1')[0]['state']);

	}//end testAnotherTenantCannotAnswerTheRequest()

	public function testAnAskerSeesOnlyTheirOwnRequests(): void {
		$service = $this->service();
		$service->request(subjectRef: 'subject-1', organisation: 'gemeente-x', reason: 'omdat');

		$this->assertSame([], $service->madeBy(subjectRef: 'subject-2'));
		$this->assertCount(1, $service->madeBy(subjectRef: 'subject-1'));

	}//end testAnAskerSeesOnlyTheirOwnRequests()

	/**
	 * portaliq#797, identity-access-requests REQ-IAR-003: a grant records the
	 * active mandate that opens the party's cases, named by who granted it.
	 *
	 * @return void
	 */
	public function testAGrantRecordsTheMandateThatOpensTheCases(): void {
		$service = $this->service();
		$request = $service->request(subjectRef: 'bookkeeper-1', organisation: 'gemeente-x', onBehalfOf: '87654321', reason: 'Ik doe de boekhouding');

		$outcome = $service->grant(id: $request['uuid'], organisation: 'gemeente-x', decidedBy: 'clerk-anna');

		$this->assertSame(PortalAccessRequestService::OUTCOME_DONE, $outcome);
		$this->assertSame('granted', $this->storedRows('portalAccessRequest')[0]['state']);
		$mandates = $this->storedRows('portalMandate');
		$this->assertCount(1, $mandates);
		$this->assertSame('bookkeeper-1', $mandates[0]['subjectRef']);
		$this->assertSame('kvk:87654321', $mandates[0]['onBehalfOf']);
		$this->assertSame('active', $mandates[0]['status']);
		$this->assertSame('organisation', $mandates[0]['reach']);
		$this->assertSame('clerk-anna', $mandates[0]['grantedBy']);
		$this->assertSame('Granted on request', $mandates[0]['label']);

	}//end testAGrantRecordsTheMandateThatOpensTheCases()

	/**
	 * REQ-IAR-003: a grant never reads granted without its mandate. When the
	 * mandate write fails, the request is pending again.
	 *
	 * @return void
	 */
	public function testAGrantWhoseMandateFailsLeavesTheRequestPending(): void {
		$writer = $this->fakeWriter();
		$failing = $this->getMockBuilder(\OCA\Portaliq\Service\PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject'])
			->getMock();
		$failing->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use ($writer): ?array {
				if ($schema === 'portalMandate') {
					return null;
				}

				return $writer->createObject($register, $schema, $scopeField, $subjectRef, $organisation, $data);
			}
		);
		$failing->method('updateObject')->willReturnCallback(
			fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): ?array => $writer->updateObject($register, $schema, $scopeField, $subjectRef, $organisation, $id, $data)
		);
		$service = new PortalAccessRequestService($this->fakeReader(), $failing);
		$request = $service->request(subjectRef: 'bookkeeper-1', organisation: 'gemeente-x', onBehalfOf: '87654321', reason: 'Ik doe de boekhouding');

		$outcome = $service->grant(id: $request['uuid'], organisation: 'gemeente-x', decidedBy: 'clerk-anna');

		$this->assertSame(PortalAccessRequestService::OUTCOME_MANDATE_FAILED, $outcome);
		$this->assertSame('pending', $this->storedRows('portalAccessRequest')[0]['state']);
		$this->assertSame([], $this->storedRows('portalMandate'));

	}//end testAGrantWhoseMandateFailsLeavesTheRequestPending()

	public function testARefusalKeepsItsReasonAndWritesNoMandate(): void {
		$service = $this->service();
		$request = $service->request(subjectRef: 'bookkeeper-1', organisation: 'gemeente-x', onBehalfOf: '87654321', reason: 'Ik doe de boekhouding');

		$outcome = $service->refuse(id: $request['uuid'], organisation: 'gemeente-x', reason: 'No authorisation from the company', decidedBy: 'clerk-anna');

		$row = $this->storedRows('portalAccessRequest')[0];
		$this->assertSame(PortalAccessRequestService::OUTCOME_DONE, $outcome);
		$this->assertSame('refused', $row['state']);
		$this->assertSame('No authorisation from the company', $row['decisionReason']);
		$this->assertSame([], $this->storedRows('portalMandate'));

	}//end testARefusalKeepsItsReasonAndWritesNoMandate()

	public function testAnAnsweredOrForeignRequestCannotBeGrantedAgain(): void {
		$service = $this->service();
		$request = $service->request(subjectRef: 'bookkeeper-1', organisation: 'gemeente-x', onBehalfOf: '87654321', reason: 'Ik doe de boekhouding');
		$service->grant(id: $request['uuid'], organisation: 'gemeente-x', decidedBy: 'clerk-anna');

		$this->assertSame(PortalAccessRequestService::OUTCOME_NOT_PENDING, $service->grant(id: $request['uuid'], organisation: 'gemeente-x', decidedBy: 'clerk-anna'));
		$this->assertSame(PortalAccessRequestService::OUTCOME_NOT_FOUND, $service->grant(id: $request['uuid'], organisation: 'gemeente-y', decidedBy: 'clerk-bert'));
		$this->assertCount(1, $this->storedRows('portalMandate'));

	}//end testAnAnsweredOrForeignRequestCannotBeGrantedAgain()

	/**
	 * The service over the fake store.
	 *
	 * @return PortalAccessRequestService
	 */
	private function service(): PortalAccessRequestService {
		return new PortalAccessRequestService($this->fakeReader(), $this->fakeWriter());
	}//end service()

}//end class
