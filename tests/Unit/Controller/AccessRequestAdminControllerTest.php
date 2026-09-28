<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\AccessRequestAdminController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * portaliq#797, identity-access-requests REQ-IAR-002: the owner's side of an
 * access request had no caller. These tests start at the routed controller
 * and assert it reaches `forOwner()`, `grant()` and `refuse()`, and that a
 * Nextcloud user without `portal.answer-access-request` reaches none of them.
 *
 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md
 */
class AccessRequestAdminControllerTest extends TestCase {

	/**
	 * The route table names the three methods on this controller.
	 *
	 * @return void
	 */
	public function testTheRoutesNameTheOwnersMethods(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$named = [];
		foreach ((array)($routes['routes'] ?? []) as $route) {
			$named[(string)$route['name']] = (string)$route['verb'] . ' ' . (string)$route['url'];
		}

		$this->assertSame('GET /api/access-requests', ($named['accessRequestAdmin#index'] ?? ''));
		$this->assertSame('POST /api/access-requests/{id}/grant', ($named['accessRequestAdmin#grant'] ?? ''));
		$this->assertSame('POST /api/access-requests/{id}/refuse', ($named['accessRequestAdmin#refuse'] ?? ''));
		foreach (['index', 'grant', 'refuse'] as $method) {
			$this->assertTrue(method_exists(AccessRequestAdminController::class, $method), $method);
		}

	}//end testTheRoutesNameTheOwnersMethods()

	public function testTheListReachesForOwner(): void {
		$requests = $this->requests();
		$requests->expects($this->once())
			->method('forOwner')
			->with($this->equalTo('gemeente-x'), $this->equalTo('pending'))
			->willReturn([['uuid' => 'req-1', 'state' => 'pending', 'organisation' => 'gemeente-x']]);

		$response = $this->controller(requests: $requests, allowed: true)->index(organisation: 'gemeente-x');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('req-1', $response->getData()['requests'][0]['uuid']);

	}//end testTheListReachesForOwner()

	public function testWithoutAnOrganisationEveryPortalsTenantIsListed(): void {
		$requests = $this->requests();
		$requests->expects($this->exactly(2))
			->method('forOwner')
			->willReturnCallback(static fn (string $organisation): array => [['uuid' => 'req-' . $organisation]]);

		$data = $this->controller(requests: $requests, allowed: true)->index()->getData();

		$this->assertSame(['req-gemeente-x', 'req-gemeente-y'], array_column($data['requests'], 'uuid'));

	}//end testWithoutAnOrganisationEveryPortalsTenantIsListed()

	public function testAGrantReachesTheServiceAndNamesTheStaffUser(): void {
		$requests = $this->requests();
		$requests->expects($this->once())
			->method('grant')
			->with($this->equalTo('req-1'), $this->equalTo('gemeente-x'), $this->equalTo('clerk-anna'))
			->willReturn(PortalAccessRequestService::OUTCOME_DONE);

		$response = $this->controller(requests: $requests, allowed: true)->grant(id: 'req-1', organisation: 'gemeente-x');

		$this->assertSame(['state' => 'granted'], $response->getData());

	}//end testAGrantReachesTheServiceAndNamesTheStaffUser()

	public function testARefusalReachesTheServiceWithItsReason(): void {
		$requests = $this->requests();
		$requests->expects($this->once())
			->method('refuse')
			->with($this->equalTo('req-1'), $this->equalTo('gemeente-x'), $this->equalTo('No authorisation from the company'), $this->equalTo('clerk-anna'))
			->willReturn(PortalAccessRequestService::OUTCOME_DONE);

		$response = $this->controller(requests: $requests, allowed: true)->refuse(id: 'req-1', organisation: 'gemeente-x', reason: 'No authorisation from the company');

		$this->assertSame(['state' => 'refused'], $response->getData());

	}//end testARefusalReachesTheServiceWithItsReason()

	public function testARefusalWithoutAReasonIsNotSaved(): void {
		$requests = $this->requests();
		$requests->expects($this->never())->method('refuse');

		$response = $this->controller(requests: $requests, allowed: true)->refuse(id: 'req-1', organisation: 'gemeente-x', reason: '  ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'reason_required'], $response->getData());

	}//end testARefusalWithoutAReasonIsNotSaved()

	/**
	 * REQ-IAR-002 "A colleague without the action cannot answer": signed in
	 * to Nextcloud is not enough.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheActionGets403AndReachesNothing(): void {
		$requests = $this->requests();
		$requests->expects($this->never())->method('forOwner');
		$requests->expects($this->never())->method('grant');
		$requests->expects($this->never())->method('refuse');
		$controller = $this->controller(requests: $requests, allowed: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->index(organisation: 'gemeente-x')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->grant(id: 'req-1', organisation: 'gemeente-x')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->refuse(id: 'req-1', organisation: 'gemeente-x', reason: 'nee')->getStatus());

	}//end testAUserWithoutTheActionGets403AndReachesNothing()

	public function testAnotherOrganisationsRequestAnswersNotFound(): void {
		$requests = $this->requests();
		$requests->method('grant')->willReturn(PortalAccessRequestService::OUTCOME_NOT_FOUND);

		$response = $this->controller(requests: $requests, allowed: true)->grant(id: 'req-of-gemeente-y', organisation: 'gemeente-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnotherOrganisationsRequestAnswersNotFound()

	public function testAGrantWhoseMandateFailedDoesNotReadGranted(): void {
		$requests = $this->requests();
		$requests->method('grant')->willReturn(PortalAccessRequestService::OUTCOME_MANDATE_FAILED);

		$response = $this->controller(requests: $requests, allowed: true)->grant(id: 'req-1', organisation: 'gemeente-x');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertArrayNotHasKey('state', $response->getData());

	}//end testAGrantWhoseMandateFailedDoesNotReadGranted()

	/**
	 * The controller over doubles of the real classes.
	 *
	 * @param PortalAccessRequestService $requests The request service double.
	 * @param bool $allowed Whether the action matrix lets the user through.
	 *
	 * @return AccessRequestAdminController
	 */
	private function controller(PortalAccessRequestService $requests, bool $allowed): AccessRequestAdminController {
		$actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		if ($allowed === false) {
			$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));
		} else {
			$actionAuth->expects($this->atLeastOnce())
				->method('requireAction')
				->with($this->anything(), $this->equalTo(AccessRequestAdminController::ACTION_ANSWER));
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk-anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$portals = $this->getMockBuilder(PortalResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['allPublishedPortals'])
			->getMock();
		$portals->method('allPublishedPortals')->willReturn([
			['slug' => 'x', 'organisation' => 'gemeente-x'],
			['slug' => 'x2', 'organisation' => 'gemeente-x'],
			['slug' => 'y', 'organisation' => 'gemeente-y'],
		]);

		return new AccessRequestAdminController($this->createMock(IRequest::class), $requests, $actionAuth, $session, $portals);
	}//end controller()

	/**
	 * A request service double limited to the methods it really has.
	 *
	 * @return PortalAccessRequestService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function requests(): PortalAccessRequestService {
		return $this->getMockBuilder(PortalAccessRequestService::class)
			->disableOriginalConstructor()
			->onlyMethods(['forOwner', 'grant', 'refuse'])
			->getMock();
	}//end requests()

}//end class
