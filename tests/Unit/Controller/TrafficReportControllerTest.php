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
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
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

		return new TrafficReportController('portaliq', $this->createMock(IRequest::class), $store, new TrafficExport(), new TrafficReportNumbers());
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
	 * A portal whose slug carries capitals is a portal like any other.
	 *
	 * Nothing in the schema constrains a slug to lower case, so an operator
	 * can create `ConductionNl` and the collector will happily record against
	 * it — the content API and the collector both match the slug exactly.
	 * Reporting used to refuse it as `missing-portal`, which is both a refusal
	 * of a portal that exists and a misleading reason for it: the measurement
	 * was collected and simply could not be read back.
	 *
	 * @return void
	 */
	public function testAPortalSlugWithCapitalsIsNotRefused(): void {
		$response = $this->controller()->export(portal: 'ConductionNl', from: '2026-09-01', to: '2026-09-04', format: 'json');
		$this->assertNotSame(
			['error' => 'missing-portal'],
			$response->getData(),
			'a slug with capitals was refused as a missing portal'
		);
	}//end testAPortalSlugWithCapitalsIsNotRefused()


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

	/**
	 * The summary folds the "all visits" records of the last 30 days
	 * when no period is given.
	 *
	 * @return void
	 */
	public function testTheSummaryFoldsTheLastThirtyDaysByDefault(): void {
		$asked = [];
		$store = $this->createMock(TrafficEventStore::class);
		$store->method('dailyBetween')->willReturnCallback(
			static function (string $portal, string $from, string $to, string $segment = '') use (&$asked): array {
				$asked = ['portal' => $portal, 'from' => $from, 'to' => $to, 'segment' => $segment];

				return [
					['portal' => $portal, 'date' => $from, 'pageViews' => 10, 'sessions' => 4, 'visitors' => 3, 'engagedSessions' => 2],
					['portal' => $portal, 'date' => $to, 'pageViews' => 5, 'sessions' => 2, 'visitors' => 2, 'engagedSessions' => 1],
				];
			}
		);
		$controller = new TrafficReportController('portaliq', $this->createMock(IRequest::class), $store, new TrafficExport(), new TrafficReportNumbers());

		$response = $controller->summary(portal: 'open-tilburg');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$today = gmdate('Y-m-d');
		$this->assertSame(['portal' => 'open-tilburg', 'from' => gmdate('Y-m-d', strtotime($today.' -29 days')), 'to' => $today, 'segment' => ''], $asked);
		$data = $response->getData();
		$this->assertSame(30, $data['days']);
		$this->assertSame(15, $data['pageViews']);
		$this->assertSame(6, $data['sessions']);
		$this->assertSame(5, $data['visitors']);
		$this->assertSame(3, $data['engagedSessions']);
	}//end testTheSummaryFoldsTheLastThirtyDaysByDefault()


	/**
	 * A seven-day period starts six days before today.
	 *
	 * @return void
	 */
	public function testTheSummaryHonoursTheChosenPeriod(): void {
		$data = $this->controller()->summary(portal: 'open-tilburg', days: '7')->getData();

		$this->assertSame(7, $data['days']);
		$this->assertSame(gmdate('Y-m-d', strtotime(gmdate('Y-m-d').' -6 days')), $data['from']);
	}//end testTheSummaryHonoursTheChosenPeriod()


	/**
	 * A malformed slug or an unknown period is refused with a reason.
	 *
	 * @return void
	 */
	public function testABadSummaryRequestIsRefusedWithAReason(): void {
		$cases = [
			['', '30', 'missing-portal'],
			['open tilburg', '30', 'missing-portal'],
			['open-tilburg', '12', 'invalid-period'],
			['open-tilburg', '30.0', 'invalid-period'],
		];
		foreach ($cases as [$portal, $days, $reason]) {
			$response = $this->controller()->summary(portal: $portal, days: $days);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $portal.'/'.$days);
			$this->assertSame(['error' => $reason], $response->getData(), $portal.'/'.$days);
		}
	}//end testABadSummaryRequestIsRefusedWithAReason()


	/**
	 * The summary is admin-only like the export, and keeps CSRF: the cards
	 * fetch it with the request token, so it needs no exemption.
	 *
	 * @return void
	 */
	public function testTheSummaryStaysAdminOnly(): void {
		$method = (new ReflectionClass(TrafficReportController::class))->getMethod('summary');

		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertEmpty($method->getAttributes(NoCSRFRequired::class));
	}//end testTheSummaryStaysAdminOnly()
}//end class
