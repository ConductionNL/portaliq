<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SubmissionAdminController;
use OCA\Portaliq\Service\StaffSubmissionReader;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * submission-row-actions 1.3: `POST /api/submissions/{id}/register-again`
 * checks the staff group before any read, answers 404 for a record the caller
 * cannot read, 409 for a delivered one, and 502 when the retry fails.
 *
 * @covers \OCA\Portaliq\Controller\SubmissionAdminController
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */
class SubmissionAdminControllerTest extends TestCase {

	/**
	 * The mocked reader.
	 *
	 * @var StaffSubmissionReader&MockObject
	 */
	private StaffSubmissionReader $reader;

	/**
	 * The mocked receipt service.
	 *
	 * @var SubmissionReceiptService&MockObject
	 */
	private SubmissionReceiptService $receipts;


	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reader   = $this->createMock(StaffSubmissionReader::class);
		$this->receipts = $this->createMock(SubmissionReceiptService::class);
	}//end setUp()


	/**
	 * The route table names the method.
	 *
	 * @return void
	 */
	public function testTheRouteNamesTheMethod(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$named  = [];
		foreach ((array)($routes['routes'] ?? []) as $route) {
			$named[(string)$route['name']] = (string)($route['verb'] ?? 'GET') . ' ' . (string)$route['url'];
		}

		$this->assertSame('POST /api/submissions/{id}/register-again', ($named['submissionAdmin#registerAgain'] ?? ''));
		$this->assertTrue(method_exists(SubmissionAdminController::class, 'registerAgain'));
	}//end testTheRouteNamesTheMethod()


	/**
	 * Without a signed-in user the answer is 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingIsRead(): void {
		$this->reader->expects($this->never())->method('find');

		$response = $this->controller(uid: null)->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testWithoutAUserNothingIsRead()


	/**
	 * Someone outside the staff group is refused before any record is read.
	 *
	 * @return void
	 */
	public function testANonStaffCallerIsRefusedBeforeAnyRead(): void {
		$this->reader->expects($this->never())->method('find');
		$this->receipts->expects($this->never())->method('registerAgain');

		$response = $this->controller(uid: 'resident', admin: false, staff: false)->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
	}//end testANonStaffCallerIsRefusedBeforeAnyRead()


	/**
	 * A record the caller cannot read answers 404, like one that does not exist.
	 *
	 * @return void
	 */
	public function testAnUnreadableRecordIsNotFound(): void {
		$this->reader->expects($this->once())->method('find')->with('sub-1')->willReturn(null);
		$this->receipts->expects($this->never())->method('registerAgain');

		$response = $this->controller(uid: 'pieter')->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnreadableRecordIsNotFound()


	/**
	 * A delivered record answers 409 and writes nothing.
	 *
	 * @return void
	 */
	public function testADeliveredRowIsRefused(): void {
		$this->reader->method('find')->willReturn(['id' => 'sub-1', 'deliveryStatus' => 'delivered']);
		$this->receipts->expects($this->never())->method('registerAgain');

		$response = $this->controller(uid: 'pieter')->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('already_delivered', $response->getData()['error']);
	}//end testADeliveredRowIsRefused()


	/**
	 * A failed record the staff member may read is registered again.
	 *
	 * @return void
	 */
	public function testAFailedRowIsRegisteredAgain(): void {
		$record = ['id' => 'sub-1', 'deliveryStatus' => 'failed', 'subjectRef' => 'bsn-1'];
		$this->reader->method('find')->willReturn($record);
		$this->receipts->expects($this->once())->method('registerAgain')->with($record)->willReturn(true);

		$response = $this->controller(uid: 'pieter')->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deliveryStatus' => 'delivered', 'message' => 'The receipt is registered again'], $response->getData());
	}//end testAFailedRowIsRegisteredAgain()


	/**
	 * A retry that fails answers 502 and says the row is still failed.
	 *
	 * @return void
	 */
	public function testAFailingRetryAnswersBadGateway(): void {
		$this->reader->method('find')->willReturn(['id' => 'sub-1', 'deliveryStatus' => 'pending']);
		$this->receipts->method('registerAgain')->willReturn(false);

		$response = $this->controller(uid: 'pieter')->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('failed', $response->getData()['deliveryStatus']);
		$this->assertSame('The receipt could not be registered again', $response->getData()['message']);
	}//end testAFailingRetryAnswersBadGateway()


	/**
	 * An administrator outside the staff group may press it too.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsStaff(): void {
		$this->reader->expects($this->once())->method('find')->willReturn(['id' => 'sub-1', 'deliveryStatus' => 'failed']);
		$this->receipts->method('registerAgain')->willReturn(true);

		$response = $this->controller(uid: 'admin', admin: true, staff: false)->registerAgain(id: 'sub-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnAdministratorIsStaff()


	/**
	 * The controller for a caller.
	 *
	 * @param string|null $uid   The signed-in user, or null for none.
	 * @param bool        $admin Whether they are an administrator.
	 * @param bool        $staff Whether they are in the staff group.
	 *
	 * @return SubmissionAdminController
	 */
	private function controller(?string $uid, bool $admin = false, bool $staff = true): SubmissionAdminController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => $staff && $group === 'portaliq-medewerkers');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new SubmissionAdminController($this->createMock(IRequest::class), $session, $groups, $this->reader, $this->receipts, $l10n);
	}//end controller()
}//end class
