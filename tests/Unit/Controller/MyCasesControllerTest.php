<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\MyCasesController;
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

	/**
	 * The controller over doubles.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param PortalCaseListReader $cases The case reader double.
	 * @param array<int, array<string, mixed>> $rows The rows it answers.
	 *
	 * @return MyCasesController
	 */
	private function controller(?array $subject, PortalCaseListReader $cases, array $rows): MyCasesController {
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
		}

		return new MyCasesController($this->createMock(IRequest::class), $registry, $session, $cases);
	}//end controller()

	/**
	 * A double that can only answer methods the real reader has.
	 *
	 * @return PortalCaseListReader&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function cases(): PortalCaseListReader {
		return $this->getMockBuilder(PortalCaseListReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['listCases'])
			->getMock();
	}//end cases()

}//end class
