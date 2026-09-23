<?php

/**
 * Portaliq Traffic Page Controller
 *
 * One portal page's traffic over a period (portal-page-traffic): the four
 * KPI cards and the incoming and outgoing traffic on a page's detail page.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficPagePath;
use OCA\Portaliq\Service\Traffic\TrafficPageReport;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * ADMIN ONLY, BY OMISSION, like the summary and the export: no
 * `NoAdminRequired` on the action. A page's audience is part of the
 * portal's audience measurement, which is an operator's surface.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
 */
class TrafficPageController extends Controller {

	/**
	 * The figures an unmeasured portal answers as null.
	 *
	 * @var string[]
	 */
	private const FIGURES = [
		'pageViews',
		'entrances',
		'exits',
		'sessions',
		'visitors',
		'engagedSessions',
		'previous',
		'next',
		'referrers',
		'outbound',
	];

	/**
	 * Constructor.
	 *
	 * @param string                $appName The app id.
	 * @param IRequest              $request The request.
	 * @param TrafficEventStore     $store   Reads the daily records.
	 * @param PortalResolver        $portals Lists the published portals.
	 * @param TrafficConfigResolver $config  Says whether a portal is measured.
	 * @param TrafficPageReport     $report  Folds one route's rows.
	 * @param TrafficPagePath       $paths   Normalises the page's route.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TrafficEventStore $store,
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly TrafficPageReport $report,
		private readonly TrafficPagePath $paths,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * One page's figures over the last days.
	 *
	 * The page is found by its route within its portal: `CmsReader::page`
	 * serves a page at exactly its stored route, and the daily rows count
	 * a view under the in-site route (TrafficPagePath). Reads the "all
	 * visits" records only, like the summary.
	 *
	 * @param string $portal The portal slug (the page's `portal`).
	 * @param string $route  The page's `route`, leading slash required.
	 * @param string $days   7, 30, 90 or 365; '' for 30.
	 *
	 * @return JSONResponse The figures, or a 400 with a reason.
	 *
	 * @auth admin-only audience measurement is an operator's surface, the same posture as the summary and the export
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
	 */
	public function page(string $portal = '', string $route = '', string $days = ''): JSONResponse {
		$reason = $this->refusal(portal: $portal, route: $route, days: $days);
		if ($reason !== null) {
			return new JSONResponse(['error' => $reason], Http::STATUS_BAD_REQUEST);
		}

		$span = 30;
		if ($days !== '') {
			$span = (int)$days;
		}

		$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
		$from = $today->modify('-'.($span - 1).' days')->format('Y-m-d');
		$to = $today->format('Y-m-d');
		$normalised = $this->paths->route(value: $route);
		$answer = ['portal' => $portal, 'route' => $normalised, 'from' => $from, 'to' => $to, 'days' => $span];

		// Not measured is null on every figure, never a zero, and the
		// records are not read: a portal that switched measurement off
		// shows "Not measured", like its own page does.
		if ($this->isMeasured(slug: $portal) === false) {
			return $this->respond(answer: $answer + ['measured' => false, 'recordedDays' => 0, 'detailDays' => 0] + array_fill_keys(self::FIGURES, null));
		}

		$records = $this->store->dailyBetween(portal: $portal, from: $from, to: $to);

		return $this->respond(answer: $answer + ['measured' => true] + $this->report->fold(records: $records, route: $normalised));
	}//end page()


	/**
	 * The answer as an uncached JSON response.
	 *
	 * @param array<string, mixed> $answer The answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(array $answer): JSONResponse {
		$response = new JSONResponse($answer);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end respond()


	/**
	 * Why the request is refused, or null.
	 *
	 * @param string $portal The portal slug.
	 * @param string $route  The route.
	 * @param string $days   The period.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(string $portal, string $route, string $days): ?string {
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/', $portal) !== 1) {
			return 'missing-portal';
		}

		if (preg_match('/^\/[^\x00-\x1f]{0,511}$/u', $route) !== 1) {
			return 'invalid-route';
		}

		if ($days !== '' && in_array($days, TrafficReportController::SUMMARY_DAYS, true) === false) {
			return 'invalid-period';
		}

		return null;
	}//end refusal()


	/**
	 * Whether the portal is published and measures its traffic.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return bool True when `traffic.enabled` is true on the published portal.
	 */
	private function isMeasured(string $slug): bool {
		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true && (string)($portal['slug'] ?? '') === $slug) {
				return $this->config->resolve(portal: $portal)['enabled'] === true;
			}
		}

		return false;
	}//end isMeasured()
}//end class
