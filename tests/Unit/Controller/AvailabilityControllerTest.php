<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use DateTime;
use OCA\Portaliq\Controller\AvailabilityController;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * operate-availability-report REQ-OAR-004: only an administrator reaches the
 * report; it answers twelve months for a published portal, and 404 for any
 * other slug.
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
class AvailabilityControllerTest extends TestCase {
	public function testNonAdminIsRefused(): void {
		$class = new ReflectionClass(AvailabilityController::class);
		foreach (['index', 'export'] as $name) {
			$method = $class->getMethod($name);
			$this->assertEmpty($method->getAttributes(PublicPage::class), $name);
			$this->assertEmpty($method->getAttributes(NoAdminRequired::class), $name);
		}
	}//end testNonAdminIsRefused()

	public function testMonthlyPercentage(): void {
		$response = $this->controller()->index(portal: 'open-tilburg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(12, $response->getData()['months']);
		$this->assertSame(100.0, $response->getData()['months'][11]['percentage']);
	}//end testMonthlyPercentage()

	public function testTheCsvIsADownload(): void {
		$response = $this->controller()->export(portal: 'open-tilburg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Read the header the controller set without getHeaders(), which asks
		// the Nextcloud server for its defaults.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('attachment; filename="availability-open-tilburg-2025-09-01-2026-08-31.csv"', $headers['Content-Disposition']);
		$this->assertStringStartsWith('month,availability_percent', $response->render());
	}//end testTheCsvIsADownload()

	public function testAnUnpublishedPortalIs404(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->index(portal: 'elders')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->export(portal: 'elders')->getStatus());
	}//end testAnUnpublishedPortalIs404()

	/**
	 * The controller over the real report and a store with one good day.
	 *
	 * @return AvailabilityController
	 */
	private function controller(): AvailabilityController {
		$store = $this->getMockBuilder(AvailabilityStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['dailyBetween', 'outagesBetween'])
			->getMock();
		$store->method('dailyBetween')->willReturn(['2026-08-10' => ['intervals' => 288, 'available' => 288]]);
		$store->method('outagesBetween')->willReturn([]);

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'open-tilburg']]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-29T10:00:00+00:00'));

		return new AvailabilityController($this->createMock(IRequest::class), new AvailabilityReport($store), $portals, $time);
	}//end controller()
}//end class
