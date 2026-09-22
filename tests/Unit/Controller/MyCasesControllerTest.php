<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\MyCasesController;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
use OCA\Portaliq\Service\PortalCaseListReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-space REQ-PIS-004 and the fail-closed half of REQ-PIS-001:
 * "Mijn zaken" answers the subject its own cases, and answers a caller with
 * no session 401 without saying anything about any account.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class MyCasesControllerTest extends TestCase {

	public function testACallerWithNoSessionIsRefusedAndToldNothing(): void {
		$cases = $this->cases();
		$cases->expects($this->never())->method('listCases');
		$controller = $this->controller(subject: null, cases: $cases, rows: []);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['authenticated' => false], $response->getData());

	}//end testACallerWithNoSessionIsRefusedAndToldNothing()

	public function testTheSubjectGetsItsOwnCases(): void {
		$controller = $this->controller(
			subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'],
			cases: $this->cases(),
			rows: [['reference' => 'ZAAK-1']]
		);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['reference' => 'ZAAK-1']], $response->getData()['cases']);

	}//end testTheSubjectGetsItsOwnCases()

	public function testAColleagueWithNoMandateSeesNoOrganisationCases(): void {
		$cases = $this->cases();
		$cases->expects($this->never())->method('listMandatedCases');
		$controller = $this->controller(
			subject: ['subjectRef' => 'colleague-3', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'],
			cases: $cases,
			rows: [],
			mandates: []
		);

		$response = $controller->index();

		$this->assertSame([], $response->getData()['cases']);
		$this->assertNull($response->getData()['activeMandate']);

	}//end testAColleagueWithNoMandateSeesNoOrganisationCases()

	public function testTheMandatedCasesAreListedBesideTheirOwn(): void {
		$controller = $this->controller(
			subject: ['subjectRef' => 'employee-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'],
			cases: $this->cases(),
			rows: [['id' => '1', 'reference' => 'MIJN-1', '_source' => ['schema' => 'zaak']]],
			mandates: [['label' => 'Voorbeeld B.V.']],
			mandatedRows: [['id' => '2', 'reference' => 'COLLEGA-1', '_source' => ['schema' => 'zaak']]]
		);

		$data = $controller->index()->getData();

		$this->assertSame(['MIJN-1', 'COLLEGA-1'], array_column($data['cases'], 'reference'));
		$this->assertSame('Voorbeeld B.V.', $data['activeMandate']['label']);

	}//end testTheMandatedCasesAreListedBesideTheirOwn()

	public function testACaseThatIsBothTheirsAndTheirCompanysIsListedOnce(): void {
		$row = ['id' => '1', 'reference' => 'ZAAK-1', '_source' => ['schema' => 'zaak']];
		$controller = $this->controller(
			subject: ['subjectRef' => 'employee-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'],
			cases: $this->cases(),
			rows: [$row],
			mandates: [['label' => 'Voorbeeld B.V.']],
			mandatedRows: [$row]
		);

		$this->assertCount(1, $controller->index()->getData()['cases']);

	}//end testACaseThatIsBothTheirsAndTheirCompanysIsListedOnce()

	public function testAGroupPastTheBoundIsRefusedRatherThanTruncated(): void {
		$cases = $this->cases();
		$cases->expects($this->never())->method('listMandatedCases');
		$controller = $this->controller(
			subject: ['subjectRef' => 'employee-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'],
			cases: $cases,
			rows: [['id' => '1', 'reference' => 'MIJN-1', '_source' => ['schema' => 'zaak']]],
			mandates: [['label' => 'Holding B.V.', 'reach' => 'tree']],
			mandatedRows: [],
			scope: ['entities' => [], 'refused' => true, 'bound' => ['maxDepth' => 4, 'pageSize' => 100]]
		);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('group_too_large', $response->getData()['error']);
		// The bound is named, and no partial list is served beside it.
		$this->assertSame(4, $response->getData()['bound']['maxDepth']);
		$this->assertArrayNotHasKey('cases', $response->getData());

	}//end testAGroupPastTheBoundIsRefusedRatherThanTruncated()

	public function testTheSwitcherOffersEveryEntityTheMandateReaches(): void {
		$controller = $this->controller(
			subject: ['subjectRef' => 'employee-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'],
			cases: $this->cases(),
			rows: [],
			mandates: [['label' => 'Holding B.V.', 'reach' => 'tree']],
			mandatedRows: [],
			scope: ['entities' => ['kvk-parent', 'kvk-sub-1', 'kvk-sub-2'], 'refused' => false, 'bound' => ['maxDepth' => 4, 'pageSize' => 100]]
		);

		$data = $controller->index()->getData();

		$this->assertSame(['kvk-parent', 'kvk-sub-1', 'kvk-sub-2'], $data['activeMandate']['entities']);

	}//end testTheSwitcherOffersEveryEntityTheMandateReaches()

	/**
	 * The controller over doubles.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param PortalCaseListReader $cases The case reader double.
	 * @param array<int, array<string, mixed>> $rows The rows it answers.
	 * @param array<int, array<string, mixed>> $mandates The mandates held.
	 * @param array<int, array<string, mixed>> $mandatedRows The mandated cases.
	 * @param array<string, mixed>|null $scope What the party tree answers.
	 *
	 * @return MyCasesController
	 */
	private function controller(?array $subject, PortalCaseListReader $cases, array $rows, array $mandates = [], array $mandatedRows = [], ?array $scope = null): MyCasesController {
		$session = $this->getMockBuilder(PortalSessionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveFromBearer'])
			->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['aggregateFor'])
			->getMock();
		$registry->method('aggregateFor')->willReturn(['contributions' => []]);

		if ($subject !== null) {
			$cases->method('listCases')->willReturn($rows);
			$cases->method('listMandatedCases')->willReturn($mandatedRows);
		}

		$mandateService = $this->getMockBuilder(PortalMandateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['mandatesFor', 'activeMandate', 'describe', 'reachOf'])
			->getMock();
		$mandateService->method('mandatesFor')->willReturn($mandates);
		$mandateService->method('activeMandate')->willReturnCallback(
			static function (array $held, string $mandateId = ''): ?array {
				if ($held === []) {
					return null;
				}

				return $held[0];
			}
		);
		$mandateService->method('reachOf')->willReturn('organisation');
		$mandateService->method('describe')->willReturnCallback(
			static function (array $mandate): array {
				return ['id' => 'mandate-1', 'label' => (string)($mandate['label'] ?? ''), 'organisation' => 'gemeente-x', 'onBehalfOf' => 'kvk-1', 'caseTypes' => []];
			}
		);

		$tree = $this->getMockBuilder(PortalPartyTreeResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['entitiesFor'])
			->getMock();
		$tree->method('entitiesFor')->willReturn(($scope ?? ['entities' => ['kvk-1'], 'refused' => false, 'bound' => ['maxDepth' => 4, 'pageSize' => 100]]));

		return new MyCasesController($this->createMock(IRequest::class), $registry, $session, $cases, $mandateService, $tree);
	}//end controller()

	/**
	 * A double that can only answer methods the real reader has.
	 *
	 * @return PortalCaseListReader&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function cases(): PortalCaseListReader {
		return $this->getMockBuilder(PortalCaseListReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['listCases', 'listMandatedCases'])
			->getMock();
	}//end cases()

}//end class
