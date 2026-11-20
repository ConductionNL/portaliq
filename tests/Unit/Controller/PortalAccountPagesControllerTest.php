<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountPagesController;
use OCA\Portaliq\Kernel\Contracts\AccountHiddenPagesInterface;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Portaliq\Kernel\StaffAction;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * operate-pages-per-portal-and-client REQ-PGC-002: only staff with the
 * account action read or set the pages hidden for one client, and the
 * check runs before the account is read.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
class PortalAccountPagesControllerTest extends TestCase {
	private const ACCOUNT = ['uuid' => 'a-1', 'subjectRef' => 'ref-1', 'audience' => 'client'];

	private AccountHiddenPagesInterface&MockObject $pages;

	private ActionAuthServiceInterface&MockObject $actionAuth;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		$this->pages      = $this->createMock(AccountHiddenPagesInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->session    = $this->createMock(IUserSession::class);
	}//end setUp()

	private function controller(mixed $hidden = null): PortalAccountPagesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['hiddenPages', null, $hidden]]);

		return new PortalAccountPagesController(
			request: $request,
			pages: $this->pages,
			actionAuth: $this->actionAuth,
			userSession: $this->session
		);
	}//end controller()

	private function signedIn(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end signedIn()

	public function testNoRouteIsPublic(): void {
		$class = new ReflectionClass(PortalAccountPagesController::class);
		foreach (['index', 'update'] as $name) {
			$this->assertEmpty($class->getMethod($name)->getAttributes(PublicPage::class), $name);
		}
	}//end testNoRouteIsPublic()

	public function testWithoutASessionIs401AndNothingIsRead(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->pages->expects($this->never())->method('accountBySubjectRef');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index(subjectRef: 'ref-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller([])->update(subjectRef: 'ref-1')->getStatus());
	}//end testWithoutASessionIs401AndNothingIsRead()

	public function testWithoutTheStaffActionIs403AndNothingIsWritten(): void {
		$this->signedIn();
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException());
		$this->pages->expects($this->never())->method('accountBySubjectRef');
		$this->pages->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index(subjectRef: 'ref-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller([])->update(subjectRef: 'ref-1')->getStatus());
	}//end testWithoutTheStaffActionIs403AndNothingIsWritten()

	public function testTheProvisionActionIsTheOneRequired(): void {
		$this->signedIn();
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->isInstanceOf(IUser::class), StaffAction::PROVISION);
		$this->pages->method('accountBySubjectRef')->with('ref-1')->willReturn(self::ACCOUNT);
		$this->pages->method('listFor')->with(self::ACCOUNT)
			->willReturn([['page' => 'pipelinq:invoices', 'hidden' => true]]);

		$response = $this->controller()->index(subjectRef: 'ref-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['pages'][0]['hidden']);
	}//end testTheProvisionActionIsTheOneRequired()

	public function testAnUnknownAccountIs404(): void {
		$this->signedIn();
		$this->pages->method('accountBySubjectRef')->willReturn(null);
		$this->pages->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->index(subjectRef: 'nobody')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller([])->update(subjectRef: 'nobody')->getStatus());
	}//end testAnUnknownAccountIs404()

	public function testAListThatIsNotAListIs400(): void {
		$this->signedIn();
		$this->pages->expects($this->never())->method('save');

		$response = $this->controller('pipelinq:invoices')->update(subjectRef: 'ref-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAListThatIsNotAListIs400()

	public function testASaveAnswersTheStoredList(): void {
		$this->signedIn();
		$saved = self::ACCOUNT + ['hiddenPages' => ['pipelinq:invoices']];
		$this->pages->method('accountBySubjectRef')->willReturn(self::ACCOUNT);
		$this->pages->expects($this->once())->method('save')->with(self::ACCOUNT, ['pipelinq:invoices'])->willReturn($saved);
		$this->pages->expects($this->once())->method('listFor')->with($saved)
			->willReturn([['page' => 'pipelinq:invoices', 'hidden' => true]]);

		$response = $this->controller(['pipelinq:invoices'])->update(subjectRef: 'ref-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('pipelinq:invoices', $response->getData()['pages'][0]['page']);
	}//end testASaveAnswersTheStoredList()

	public function testAFailedWriteIs502(): void {
		$this->signedIn();
		$this->pages->method('accountBySubjectRef')->willReturn(self::ACCOUNT);
		$this->pages->method('save')->willReturn(null);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller([])->update(subjectRef: 'ref-1')->getStatus());
	}//end testAFailedWriteIs502()
}//end class
