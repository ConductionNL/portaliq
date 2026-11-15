<?php

/**
 * Unit tests for TrafficPageController (portal-page-traffic).
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

use OCA\Portaliq\Controller\TrafficPageController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficPagePath;
use OCA\Portaliq\Service\Traffic\TrafficPageReport;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * One page's figures: folded from the "all visits" records by route, null
 * where nothing was counted, refused with a reason, admin-only.
 */
class TrafficPageControllerTest extends TestCase {

	/**
	 * What the store was asked, or null when it was not.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $asked = null;

	/**
	 * A page row with the per-page figures.
	 *
	 * @param string $path  The path.
	 * @param int    $views Its views.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $path, int $views): array {
		return [
			'path' => $path,
			'views' => $views,
			'entrances' => 1,
			'exits' => 1,
			'avgEngagementSeconds' => 3.0,
			'sessions' => 2,
			'visitors' => 2,
			'engagedSessions' => 1,
			'referrers' => [['host' => 'www.google.com', 'channel' => 'organic', 'count' => 1]],
			'outbound' => [['url' => 'https://www.tilburg.nl/', 'count' => 1]],
		];
	}//end row()


	/**
	 * The controller over two published portals, one measured.
	 *
	 * @param array<int, array<string, mixed>> $records What the store answers.
	 *
	 * @return TrafficPageController The controller.
	 */
	private function controller(array $records): TrafficPageController {
		$this->asked = null;
		$store = $this->createMock(TrafficEventStore::class);
		$store->method('dailyBetween')->willReturnCallback(
			function (string $portal, string $from, string $to, string $segment = '') use ($records): array {
				$this->asked = ['portal' => $portal, 'from' => $from, 'to' => $to, 'segment' => $segment];

				return $records;
			}
		);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(
			[
				['slug' => 'open-tilburg', 'traffic' => ['enabled' => true]],
				['slug' => 'open-venray', 'traffic' => ['enabled' => false]],
			]
		);

		return new TrafficPageController(
			'portaliq',
			$this->createMock(IRequest::class),
			$store,
			$portals,
			new TrafficConfigResolver(),
			new TrafficPageReport(),
			new TrafficPagePath()
		);
	}//end controller()


	/**
	 * The last 30 days by default: the route's rows, its transitions and
	 * its lists, from the "all visits" records.
	 *
	 * @return void
	 */
	public function testAPagesFiguresForTheLastThirtyDays(): void {
		$records = [
			[
				'date' => '2026-09-01',
				'pages' => [$this->row(path: '/contact', views: 3), $this->row(path: '/', views: 9)],
				'transitions' => [['from' => '/', 'to' => '/contact', 'count' => 2], ['from' => '/contact', 'to' => '/woo', 'count' => 1]],
			],
			[
				'date' => '2026-09-02',
				'pages' => [$this->row(path: '/contact/', views: 4)],
				'transitions' => [['from' => '/', 'to' => '/contact/', 'count' => 1]],
			],
		];

		$response = $this->controller(records: $records)->page(portal: 'open-tilburg', route: '/contact');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$today = gmdate('Y-m-d');
		$this->assertSame(['portal' => 'open-tilburg', 'from' => gmdate('Y-m-d', strtotime($today.' -29 days')), 'to' => $today, 'segment' => ''], $this->asked);
		$data = $response->getData();
		$this->assertTrue($data['measured']);
		$this->assertSame('/contact', $data['route']);
		$this->assertSame(30, $data['days']);
		$this->assertSame(7, $data['pageViews'], 'the trailing-slash row is the same page');
		$this->assertSame(4, $data['sessions']);
		$this->assertSame(4, $data['visitors']);
		$this->assertSame(2, $data['engagedSessions']);
		$this->assertSame(2, $data['entrances']);
		$this->assertSame([['path' => '/', 'count' => 3]], $data['previous']);
		$this->assertSame([['path' => '/woo', 'count' => 1]], $data['next']);
		$this->assertSame([['host' => 'www.google.com', 'channel' => 'organic', 'count' => 2]], $data['referrers']);
		$this->assertSame([['url' => 'https://www.tilburg.nl/', 'count' => 2]], $data['outbound']);
		$this->assertSame(2, $data['detailDays']);
		$this->assertSame(2, $data['recordedDays']);
	}//end testAPagesFiguresForTheLastThirtyDays()


	/**
	 * A day written before the per-page figures counts towards the views
	 * but not the sessions; with no such day at all they are null.
	 *
	 * @return void
	 */
	public function testDaysWithoutPerPageFiguresAreCountedNotZeroed(): void {
		$old = ['date' => '2026-06-01', 'pages' => [['path' => '/contact', 'views' => 5, 'entrances' => 2, 'exits' => 2, 'avgEngagementSeconds' => 3.0]]];
		$new = ['date' => '2026-09-01', 'pages' => [$this->row(path: '/contact', views: 3)]];

		$mixed = $this->controller(records: [$old, $new])->page(portal: 'open-tilburg', route: '/contact', days: '365')->getData();
		$this->assertSame(8, $mixed['pageViews']);
		$this->assertSame(2, $mixed['sessions']);
		$this->assertSame(1, $mixed['detailDays']);
		$this->assertSame(2, $mixed['recordedDays']);

		$onlyOld = $this->controller(records: [$old])->page(portal: 'open-tilburg', route: '/contact', days: '365')->getData();
		$this->assertSame(5, $onlyOld['pageViews']);
		$this->assertNull($onlyOld['sessions']);
		$this->assertNull($onlyOld['visitors']);
		$this->assertNull($onlyOld['engagedSessions']);
		$this->assertNull($onlyOld['referrers']);
		$this->assertNull($onlyOld['outbound']);
		$this->assertSame(0, $onlyOld['detailDays']);
	}//end testDaysWithoutPerPageFiguresAreCountedNotZeroed()


	/**
	 * An unmeasured portal answers null on every figure and reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnmeasuredPortalAnswersNull(): void {
		foreach (['open-venray', 'not-published'] as $slug) {
			$data = $this->controller(records: [['pages' => [$this->row(path: '/', views: 1)]]])->page(portal: $slug, route: '/')->getData();

			$this->assertFalse($data['measured'], $slug);
			$this->assertNull($data['pageViews'], $slug);
			$this->assertNull($data['sessions'], $slug);
			$this->assertNull($data['previous'], $slug);
			$this->assertNull($this->asked, $slug.': the records were not read');
		}
	}//end testAnUnmeasuredPortalAnswersNull()


	/**
	 * A malformed slug, route or period is refused with a reason.
	 *
	 * @return void
	 */
	public function testABadRequestIsRefusedWithAReason(): void {
		$cases = [
			['', '/', '30', 'missing-portal'],
			['open tilburg', '/', '30', 'missing-portal'],
			['open-tilburg', 'contact', '30', 'invalid-route'],
			['open-tilburg', '', '30', 'invalid-route'],
			['open-tilburg', "/a\nb", '30', 'invalid-route'],
			['open-tilburg', '/', '12', 'invalid-period'],
		];
		foreach ($cases as [$portal, $route, $days, $reason]) {
			$response = $this->controller(records: [])->page(portal: $portal, route: $route, days: $days);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $reason);
			$this->assertSame(['error' => $reason], $response->getData(), $reason);
		}
	}//end testABadRequestIsRefusedWithAReason()


	/**
	 * Admin-only like the summary, and keeps CSRF.
	 *
	 * @return void
	 */
	public function testThePageEndpointStaysAdminOnly(): void {
		$method = (new ReflectionClass(TrafficPageController::class))->getMethod('page');

		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertEmpty($method->getAttributes(NoCSRFRequired::class));
	}//end testThePageEndpointStaysAdminOnly()
}//end class
