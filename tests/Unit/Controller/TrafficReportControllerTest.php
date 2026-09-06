<?php

/**
 * Unit tests for TrafficReportController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\TrafficReportController;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficExport;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The export answers a file for a good request, a 400 with a reason for
 * a bad one, and stays admin-only.
 */
class TrafficReportControllerTest extends TestCase {

	/**
	 * The controller over a store that answers one record.
	 *
	 * @return TrafficReportController The controller.
	 */
	private function controller(): TrafficReportController {
		$store = $this->createMock(TrafficEventStore::class);
		$store->method('dailyBetween')->willReturnCallback(
			static fn (string $portal, string $from, string $to, string $segment = ''): array => [
				['portal' => $portal, 'date' => $from, 'segment' => $segment, 'pageViews' => 7],
			]
		);

		return new TrafficReportController('portaliq', $this->createMock(IRequest::class), $store, new TrafficExport());
	}//end controller()


	/**
	 * The headers a response was given.
	 *
	 * @param DataDisplayResponse $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headers(DataDisplayResponse $response): array {
		$property = new ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');

		return $property->getValue($response);
	}//end headers()


	/**
	 * @return void
	 */
	public function testACsvExportIsADownloadWithTheHeaderAndARow(): void {
		$response = $this->controller()->export(portal: 'open-tilburg', from: '2026-09-01', to: '2026-09-04', segment: 'desktop', format: 'csv');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$headers = $this->headers($response);
		$this->assertSame('text/csv; charset=utf-8', $headers['Content-Type']);
		$this->assertSame('attachment; filename="traffic-open-tilburg-2026-09-01-2026-09-04-desktop.csv"', $headers['Content-Disposition']);
		$lines = explode("\r\n", trim($response->render()));
		$this->assertStringStartsWith('portal,date,segment,pageViews', $lines[0]);
		$this->assertSame('open-tilburg,2026-09-01,desktop,7,,,,,,,,,', $lines[1]);

		$json = $this->controller()->export(portal: 'open-tilburg', from: '2026-09-01', to: '2026-09-04', format: 'json');
		$this->assertSame('application/json; charset=utf-8', $this->headers($json)['Content-Type']);
		$this->assertSame(7, json_decode($json->render(), true)[0]['pageViews']);
	}//end testACsvExportIsADownloadWithTheHeaderAndARow()


	/**
	 * @return void
	 */
	public function testABadRequestIsRefusedWithAReason(): void {
		$cases = [
			'missing-portal' => ['', '2026-09-01', '2026-09-04', '', 'csv'],
			'invalid-range' => ['open-tilburg', '2026-09-04', '2026-09-01', '', 'csv'],
			'range-too-long' => ['open-tilburg', '2024-01-01', '2026-09-01', '', 'csv'],
			'invalid-segment' => ['open-tilburg', '2026-09-01', '2026-09-04', 'bad segment', 'csv'],
			'invalid-format' => ['open-tilburg', '2026-09-01', '2026-09-04', '', 'xlsx'],
		];
		foreach ($cases as $reason => [$portal, $from, $to, $segment, $format]) {
			$response = $this->controller()->export(portal: $portal, from: $from, to: $to, segment: $segment, format: $format);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $reason);
			$this->assertSame(['error' => $reason], $response->getData());
		}
	}//end testABadRequestIsRefusedWithAReason()


	/**
	 * Admin-only by omission: no public or no-admin attribute, and the
	 * CSRF exemption a navigated download needs.
	 *
	 * @return void
	 */
	public function testTheExportStaysAdminOnly(): void {
		$method = (new ReflectionClass(TrafficReportController::class))->getMethod('export');

		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
	}//end testTheExportStaysAdminOnly()
}//end class
