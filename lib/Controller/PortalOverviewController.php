<?php

/**
 * Portaliq portal overview controller
 *
 * The admin routes behind the dashboard's figures across all portals:
 * traffic over the last days and availability over the last full month.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Overview\PortalOverview;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Figures of all published portals together, for the dashboard.
 *
 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
 */
class PortalOverviewController extends Controller {
	/**
	 * The periods the traffic overview answers, in days.
	 */
	public const TRAFFIC_DAYS = ['7', '30'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalOverview $overview Folds the portals' figures.
	 * @param ITimeFactory $time The clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalOverview $overview,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Traffic of all published portals over the last 7 or 30 days.
	 *
	 * @param string $days 7 or 30; '' for 7.
	 *
	 * @return JSONResponse The totals and the most viewed pages, or 400.
	 *
	 * @auth admin-only audience measurement is an operator's surface, the
	 *       same posture as the traffic summary. Nextcloud checks it before
	 *       this method runs; admin-only is the absence of an opt-out attribute.
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function traffic(string $days = ''): JSONResponse {
		if ($days === '') {
			$days = '7';
		}

		if (in_array($days, self::TRAFFIC_DAYS, true) === false) {
			return new JSONResponse(['error' => 'invalid-period'], Http::STATUS_BAD_REQUEST);
		}

		$response = new JSONResponse($this->overview->traffic(days: (int)$days, now: $this->now()));
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end traffic()

	/**
	 * Availability of all published portals over the last full month.
	 *
	 * @return JSONResponse The percentage per portal, together and the outages.
	 *
	 * @auth admin-only availability is an operator's surface, the same posture
	 *       as the availability report.
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function availability(): JSONResponse {
		$response = new JSONResponse($this->overview->availability(now: $this->now()));
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end availability()

	/**
	 * The moment of the request.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable($this->time->getDateTime()->format(DATE_ATOM));
	}//end now()
}//end class
