<?php

/**
 * Tests for DeskSessionController (resident-identity-in-forms T06).
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\DeskSessionController;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalRegisteredDetailsServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\OnBehalfFilling;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Inloggen met werkaccount": only a desk group member gets a desk session,
 * and only for a form that allows staff.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
class DeskSessionControllerTest extends TestCase {

	private PortalSessionServiceInterface&MockObject $session;

	private ?IUser $user = null;

	private bool $inGroup = true;

	private ?array $binding = ['portal' => 'gemeente-x', 'route' => 'woo', 'staffMayFill' => true];

	protected function setUp(): void {
		$this->session = $this->createMock(PortalSessionServiceInterface::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('balie1');
		$user->method('getDisplayName')->willReturn('Bart Balie');
		$this->user = $user;
	}//end setUp()

	public function testADeskMemberGetsALowTrustDeskSessionInTheFragment(): void {
		$this->session->expects($this->once())->method('issueSession')->with(
			'balie1',
			'desk',
			'org-x',
			'low',
			['desk:fill'],
			'',
			'desk'
		)->willReturn(['token' => 'tok en']);

		$response = $this->controller()->start(portal: 'gemeente-x', route: 'woo', returnTo: '/apps/portaliq/site?portal=gemeente-x&form=woo');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
		$this->assertSame('https://nc.example/apps/portaliq/site?portal=gemeente-x&form=woo#token=tok%20en', $response->getRedirectURL());
	}//end testADeskMemberGetsALowTrustDeskSessionInTheFragment()

	public function testAReturnAddressOnAnotherHostIsReplacedByThePortalsSite(): void {
		$this->session->method('issueSession')->willReturn(['token' => 't']);

		foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example'] as $returnTo) {
			$response = $this->controller()->start(portal: 'gemeente-x', route: 'woo', returnTo: $returnTo);
			$this->assertSame('https://nc.example/apps/portaliq/site?portal=gemeente-x#token=t', $response->getRedirectURL());
		}
	}//end testAReturnAddressOnAnotherHostIsReplacedByThePortalsSite()

	public function testAUserOutsideTheDeskGroupIsRefused(): void {
		$this->inGroup = false;
		$this->session->expects($this->never())->method('issueSession');

		$response = $this->controller()->start(portal: 'gemeente-x', route: 'woo');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame([Http::STATUS_FORBIDDEN, ['error' => 'not_desk_staff']], [$response->getStatus(), $response->getData()]);
	}//end testAUserOutsideTheDeskGroupIsRefused()

	public function testAFormThatDoesNotAllowStaffIsNotFound(): void {
		$this->binding['staffMayFill'] = false;
		$this->session->expects($this->never())->method('issueSession');

		$response = $this->controller()->start(portal: 'gemeente-x', route: 'woo');

		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'form_not_found']], [$response->getStatus(), $response->getData()]);
	}//end testAFormThatDoesNotAllowStaffIsNotFound()

	public function testWithoutANextcloudSessionTheUserIsSentToTheLoginForm(): void {
		$this->user = null;
		$this->session->expects($this->never())->method('issueSession');

		$response = $this->controller()->start(portal: 'gemeente-x', route: 'woo');

		$this->assertSame('/login?redirect_url=%2Fapps%2Fportaliq%2Fportal%2Fapi%2Fsession%2Fdesk', $response->getRedirectURL());
	}//end testWithoutANextcloudSessionTheUserIsSentToTheLoginForm()

	public function testNoSessionMintedIsServiceUnavailable(): void {
		$this->session->method('issueSession')->willReturn(null);

		$response = $this->controller()->start(portal: 'gemeente-x', route: 'woo');

		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, ['error' => 'not_configured']], [$response->getStatus(), $response->getData()]);
	}//end testNoSessionMintedIsServiceUnavailable()

	/**
	 * The controller over the doubles.
	 *
	 * @return DeskSessionController
	 */
	private function controller(): DeskSessionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn('/apps/portaliq/portal/api/session/desk');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->user);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args): string => '/login?redirect_url=' . rawurlencode($args['redirect_url']));
		$portals = $this->createMock(PortalResolverInterface::class);
		$portals->method('resolve')->willReturn(['slug' => 'gemeente-x', 'organisation' => 'org-x', 'authentication' => ['deskGroup' => 'balie']]);
		$bindings = $this->createMock(PortalFormBindingResolverInterface::class);
		$bindings->method('bindingFor')->willReturn($this->binding);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $this->inGroup && $group === 'balie');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->user);

		return new DeskSessionController(
			request: $request,
			userSession: $userSession,
			urlGenerator: $urls,
			portals: $portals,
			bindings: $bindings,
			session: $this->session,
			onBehalf: new OnBehalfFilling(
				groups: $groups,
				users: $users,
				details: $this->createMock(PortalRegisteredDetailsServiceInterface::class),
				audit: $this->createMock(AuditTrailService::class)
			)
		);
	}//end controller()
}//end class
