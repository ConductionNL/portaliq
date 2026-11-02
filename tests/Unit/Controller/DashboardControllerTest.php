<?php

/**
 * DashboardController contract tests.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\DashboardController;
use OCA\Portaliq\Service\AdminMenuAccess;
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Wire contract for `GET /` (`dashboard#page`) and the history-mode deep-link
 * catch-all (`dashboard#catchAll`).
 *
 * The SPA is a Vue history-mode app, so EVERY in-app deep link is served by the
 * same template as the index. That is the contract: `catchAll()` must render
 * the identical app/template pair as `page()`, because a deep link that
 * rendered a different template — or a different app's template — would 404 or
 * boot the wrong bundle on reload.
 *
 * The assertions are on the ITEM: the app name and template name the response
 * actually carries. Asserting merely that a `TemplateResponse` came back would
 * stay green if the template name changed to something that does not exist.
 *
 * @covers \OCA\Portaliq\Controller\DashboardController
 */
class DashboardControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var DashboardController
	 */
	private DashboardController $controller;

	/**
	 * Build the controller with a mocked request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->controller = new DashboardController($this->createMock(IRequest::class), $this->access(staff: true));
	}//end setUp()

	/**
	 * An access double that answers whether the user is staff.
	 *
	 * @param bool $staff Whether the signed-in user is staff.
	 *
	 * @return AdminMenuAccess
	 */
	private function access(bool $staff): AdminMenuAccess {
		$access = $this->createMock(AdminMenuAccess::class);
		$access->method('isStaff')->willReturn($staff);
		$access->method('forCurrentUser')->willReturn(['admin' => false, 'pages' => false, 'accounts' => false, 'accessRequests' => false]);

		return $access;
	}//end access()

	/**
	 * `GET /` renders portaliq's own `index` template.
	 *
	 * @return void
	 */
	public function testPageRendersThePortaliqIndexTemplate(): void {
		$response = $this->controller->page();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('portaliq', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
	}//end testPageRendersThePortaliqIndexTemplate()

	/**
	 * A deep link renders the SAME app/template pair, so a browser reload on an
	 * in-app route boots the same SPA rather than 404ing.
	 *
	 * @return void
	 */
	public function testCatchAllRendersTheSameTemplateAsPage(): void {
		$page = $this->controller->page();
		$catchAll = $this->controller->catchAll();

		$this->assertSame($page->getApp(), $catchAll->getApp());
		$this->assertSame($page->getTemplateName(), $catchAll->getTemplateName());
		$this->assertSame('index', $catchAll->getTemplateName());
	}//end testCatchAllRendersTheSameTemplateAsPage()

	/**
	 * The page hands the app the signed-in user's access flags, so the menu
	 * shows only what their role may use (admin-menu-follows-roles).
	 *
	 * @return void
	 */
	public function testThePageHandsTheAppTheAccessFlags(): void {
		$flags = ['admin' => false, 'pages' => false, 'accounts' => false, 'accessRequests' => false];
		$access = $this->createMock(AdminMenuAccess::class);
		$access->method('isStaff')->willReturn(true);
		$access->method('forCurrentUser')->willReturn($flags);
		$state = $this->createMock(IInitialState::class);
		$state->expects($this->once())->method('provideInitialState')->with('access', $flags);

		(new DashboardController($this->createMock(IRequest::class), $access, $state))->page();
	}//end testThePageHandsTheAppTheAccessFlags()

	/**
	 * A signed-in account that is not staff is refused, and gets no access flags.
	 *
	 * Found live on 2026-10-14: an account in no group opened /apps/portaliq/
	 * and was shown the staff dashboard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
	 */
	public function testAnAccountThatIsNotStaffIsRefused(): void {
		$state = $this->createMock(IInitialState::class);
		$state->expects($this->never())->method('provideInitialState');

		$controller = new DashboardController($this->createMock(IRequest::class), $this->access(staff: false), $state);

		foreach ([$controller->page(), $controller->catchAll()] as $response) {
			$this->assertInstanceOf(TemplateResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('core', $response->getApp());
			$this->assertSame('403', $response->getTemplateName());
		}
	}//end testAnAccountThatIsNotStaffIsRefused()

	/**
	 * Without the access service nobody can be shown to be staff: the page fails closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
	 */
	public function testWithoutTheAccessServiceThePageFailsClosed(): void {
		$response = (new DashboardController($this->createMock(IRequest::class)))->page();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testWithoutTheAccessServiceThePageFailsClosed()

	/**
	 * A resident is still sent to their portal before the staff check runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
	 */
	public function testAResidentIsStillSentToTheirPortal(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('inwoner-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$residents = $this->createMock(NextcloudRegistrationDoor::class);
		$residents->method('residentPortalOf')->with('inwoner-1')->willReturn(['slug' => 'zuiddrecht']);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/portaliq/portal/api/session/nextcloud?portal=zuiddrecht');

		$response = (new DashboardController($this->createMock(IRequest::class), $this->access(staff: false), null, $residents, $session, $urls))->page();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/portaliq/portal/api/session/nextcloud?portal=zuiddrecht', $response->getRedirectURL());
	}//end testAResidentIsStillSentToTheirPortal()
}//end class
