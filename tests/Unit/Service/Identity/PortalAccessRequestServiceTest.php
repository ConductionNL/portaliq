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
	 * The service over the fake store.
	 *
	 * @return PortalAccessRequestService
	 */
	private function service(): PortalAccessRequestService {
		return new PortalAccessRequestService($this->fakeReader(), $this->fakeWriter());
	}//end service()

}//end class
