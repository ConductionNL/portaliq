<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAdminGroupsController;
use OCA\Portaliq\Service\Tenancy\PortalAdminGroups;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Administrator groups card: read per portal, written by an instance
 * administrator only (operate-portals-per-organisation REQ-OPO-005).
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
class PortalAdminGroupsControllerTest extends TestCase {

	private const PORTAL = ['id' => 'p-1', 'slug' => 'zeist', 'adminGroups' => ['zeist-beheer']];

	public function testTheReadIsPerPortalAndTheWriteAdminOnly(): void {
		$class = new ReflectionClass(PortalAdminGroupsController::class);

		$this->assertNotEmpty($class->getMethod('index')->getAttributes(NoAdminRequired::class));
		$this->assertEmpty($class->getMethod('update')->getAttributes(NoAdminRequired::class));
	}//end testTheReadIsPerPortalAndTheWriteAdminOnly()

	public function testTheRoutesNameTheMethods(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$named  = [];
		foreach ((array)($routes['routes'] ?? []) as $route) {
			$named[(string)$route['name']] = (string)$route['verb'] . ' ' . (string)$route['url'];
		}

		$this->assertSame('GET /api/portals/{slug}/admin-groups', ($named['portalAdminGroups#index'] ?? ''));
		$this->assertSame('PUT /api/portals/{slug}/admin-groups', ($named['portalAdminGroups#update'] ?? ''));
	}//end testTheRoutesNameTheMethods()

	public function testAMemberReadsTheListWithoutThePicker(): void {
		$groups = $this->adminGroups();
		$groups->expects($this->never())->method('available');

		$response = $this->controller(allowed: true, admin: false, groups: $groups)->index(slug: 'zeist');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 'zeist-beheer', 'label' => 'Zeist beheer']], $response->getData()['groups']);
		$this->assertFalse($response->getData()['canChange']);
		$this->assertSame([], $response->getData()['available']);
	}//end testAMemberReadsTheListWithoutThePicker()

	public function testAnAdministratorGetsThePicker(): void {
		$groups = $this->adminGroups();
		$groups->method('available')->willReturn([['id' => 'zeist-beheer', 'label' => 'Zeist beheer'], ['id' => 'utrecht-beheer', 'label' => 'Utrecht beheer']]);

		$response = $this->controller(allowed: true, admin: true, groups: $groups)->index(slug: 'zeist');

		$this->assertTrue($response->getData()['canChange']);
		$this->assertCount(2, $response->getData()['available']);
	}//end testAnAdministratorGetsThePicker()

	public function testAnotherOrganisationsPortalIs404(): void {
		$groups = $this->adminGroups();
		$groups->expects($this->never())->method('listFor');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(allowed: false, admin: false, groups: $groups)->index(slug: 'zeist')->getStatus());
	}//end testAnotherOrganisationsPortalIs404()

	public function testASaveStoresAndAnswersTheList(): void {
		$groups = $this->adminGroups();
		$groups->expects($this->once())->method('save')->with(self::PORTAL, ['zeist-beheer'])->willReturn(self::PORTAL);

		$response = $this->controller(allowed: true, admin: true, groups: $groups)->update(slug: 'zeist', groups: ['zeist-beheer']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('zeist-beheer', $response->getData()['groups'][0]['id']);
	}//end testASaveStoresAndAnswersTheList()

	public function testAFailedSaveIs502(): void {
		$groups = $this->adminGroups();
		$groups->method('save')->willReturn(null);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller(allowed: true, admin: true, groups: $groups)->update(slug: 'zeist', groups: [])->getStatus());
	}//end testAFailedSaveIs502()

	/**
	 * A groups double that lists the portal's one group.
	 *
	 * @return PortalAdminGroups&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function adminGroups(): PortalAdminGroups {
		$groups = $this->getMockBuilder(PortalAdminGroups::class)
			->disableOriginalConstructor()
			->onlyMethods(['listFor', 'available', 'save'])
			->getMock();
		$groups->method('listFor')->willReturn([['id' => 'zeist-beheer', 'label' => 'Zeist beheer']]);

		return $groups;
	}//end adminGroups()

	/**
	 * The controller over a scope double.
	 *
	 * @param bool              $allowed Whether the caller administers the portal.
	 * @param bool              $admin   Whether the caller administers the instance.
	 * @param PortalAdminGroups $groups  The groups double.
	 *
	 * @return PortalAdminGroupsController
	 */
	private function controller(bool $allowed, bool $admin, PortalAdminGroups $groups): PortalAdminGroupsController {
		$scope = $this->getMockBuilder(PortalAdminScope::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'mayAdminister', 'mayChangeAdminGroups'])
			->getMock();
		$scope->method('portalBySlug')->willReturn(self::PORTAL);
		$scope->method('mayAdminister')->willReturn($allowed);
		$scope->method('mayChangeAdminGroups')->willReturn($admin);

		return new PortalAdminGroupsController($this->createMock(IRequest::class), $scope, $groups);
	}//end controller()
}//end class
