<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalSigninController;
use OCA\Portaliq\Service\Signin\PortalSigninSettings;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A portal's sign-in settings are read and changed per portal
 * (operate-portals-per-organisation REQ-OPO-005).
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 */
class PortalSigninControllerTest extends TestCase {

	public function testAMemberReadsAndSavesTheirOwnPortal(): void {
		$settings = $this->settings();
		$settings->method('view')->willReturn(['routes' => []]);
		$settings->expects($this->once())->method('save')->willReturn(['routes' => []]);
		$controller = $this->controller(settings: $settings, allowed: true);

		$this->assertSame(Http::STATUS_OK, $controller->index(slug: 'zeist')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->update(slug: 'zeist')->getStatus());
	}//end testAMemberReadsAndSavesTheirOwnPortal()

	public function testAnotherOrganisationsPortalIs404AndUnchanged(): void {
		$settings = $this->settings();
		$settings->expects($this->never())->method('view');
		$settings->expects($this->never())->method('save');
		$controller = $this->controller(settings: $settings, allowed: false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index(slug: 'utrecht')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update(slug: 'utrecht', secret: 'x')->getStatus());
	}//end testAnotherOrganisationsPortalIs404AndUnchanged()

	/**
	 * A settings double that finds the portal.
	 *
	 * @return PortalSigninSettings&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settings(): PortalSigninSettings {
		$settings = $this->getMockBuilder(PortalSigninSettings::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'view', 'save'])
			->getMock();
		$settings->method('portalBySlug')->willReturn(['slug' => 'zeist']);

		return $settings;
	}//end settings()

	/**
	 * The controller over a scope double.
	 *
	 * @param PortalSigninSettings $settings The settings double.
	 * @param bool                 $allowed  Whether the caller administers the portal.
	 *
	 * @return PortalSigninController
	 */
	private function controller(PortalSigninSettings $settings, bool $allowed): PortalSigninController {
		$scope = $this->getMockBuilder(PortalAdminScope::class)->disableOriginalConstructor()->onlyMethods(['mayAdminister'])->getMock();
		$scope->method('mayAdminister')->willReturn($allowed);

		return new PortalSigninController($this->createMock(IRequest::class), $settings, $scope);
	}//end controller()
}//end class
