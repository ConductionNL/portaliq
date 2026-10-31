<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use DateTime;
use OCA\Portaliq\Controller\PortalOverviewController;
use OCA\Portaliq\Service\Overview\PortalOverview;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Only an administrator reaches the dashboard's figures across portals.
 *
 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
 */
class PortalOverviewControllerTest extends TestCase {
	/**
	 * Neither route opts out of Nextcloud's admin check.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorIsAnswered(): void {
		$class = new ReflectionClass(objectOrClass: PortalOverviewController::class);
		foreach (['traffic', 'availability'] as $name) {
			$method = $class->getMethod(name: $name);
			$this->assertEmpty(actual: $method->getAttributes(name: PublicPage::class), message: $name);
			$this->assertEmpty(actual: $method->getAttributes(name: NoAdminRequired::class), message: $name);
		}
	}//end testOnlyAnAdministratorIsAnswered()

	/**
	 * Seven days by default, 30 on request, anything else refused before reading.
	 *
	 * @return void
	 */
	public function testTrafficTakesSevenOrThirtyDays(): void {
		$overview = $this->createMock(originalClassName: PortalOverview::class);
		$overview->expects($this->exactly(count: 2))->method('traffic')->willReturnCallback(
			static fn (int $days): array => ['days' => $days]
		);
		$controller = $this->controller(overview: $overview);

		$this->assertSame(expected: ['days' => 7], actual: $controller->traffic()->getData());
		$this->assertSame(expected: ['days' => 30], actual: $controller->traffic(days: '30')->getData());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $controller->traffic(days: '9999')->getStatus());
	}//end testTrafficTakesSevenOrThirtyDays()

	/**
	 * The availability answers what the overview folds.
	 *
	 * @return void
	 */
	public function testAvailabilityAnswersTheOverview(): void {
		$overview = $this->createMock(originalClassName: PortalOverview::class);
		$overview->method('availability')->willReturn(value: ['percentage' => 99.95]);

		$response = $this->controller(overview: $overview)->availability();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['percentage' => 99.95], actual: $response->getData());
	}//end testAvailabilityAnswersTheOverview()

	/**
	 * The controller at a fixed moment.
	 *
	 * @param PortalOverview $overview The overview double.
	 *
	 * @return PortalOverviewController
	 */
	private function controller(PortalOverview $overview): PortalOverviewController {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(value: new DateTime(datetime: '2026-10-07T09:00:00+00:00'));

		return new PortalOverviewController(request: $this->createMock(originalClassName: IRequest::class), overview: $overview, time: $time);
	}//end controller()
}//end class
