<?php

/**
 * Portaliq availability controller
 *
 * The admin routes behind the availability report: a portal's last twelve
 * months as JSON, and the same as a CSV download.
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
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Serves one portal's availability report to an administrator.
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
class AvailabilityController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param AvailabilityReport $report Builds the report.
	 * @param PortalResolver $portals Lists the published portals.
	 * @param ITimeFactory $time The clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly AvailabilityReport $report,
		private readonly PortalResolver $portals,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A portal's availability per month and its outages.
	 *
	 * @param string $portal The portal slug.
	 * @param int $months How many full months, 1 to 12.
	 *
	 * @return JSONResponse The report, or 404 for a portal that is not published.
	 *
	 * @auth admin-only availability is an operator's surface, the same posture as /api/metrics. Nextcloud expresses admin-only as the ABSENCE of an opt-out attribute.
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function index(string $portal, int $months = 12): JSONResponse {
		if ($this->isPublished(portal: $portal) === false) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->build(portal: $portal, months: $months));
	}//end index()

	/**
	 * The same report as a CSV download.
	 *
	 * @param string $portal The portal slug.
	 * @param int $months How many full months, 1 to 12.
	 *
	 * @return Response The file, or 404 for a portal that is not published.
	 *
	 * @auth admin-only availability is an operator's surface. The CSRF exemption is what a navigated download needs; the admin check still applies.
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	#[NoCSRFRequired]
	public function export(string $portal, int $months = 12): Response {
		if ($this->isPublished(portal: $portal) === false) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$report = $this->build(portal: $portal, months: $months);
		$response = new DataDisplayResponse($this->report->csv(report: $report), Http::STATUS_OK, ['Content-Type' => 'text/csv; charset=utf-8']);
		$response->addHeader('Content-Disposition', 'attachment; filename="availability-' . $portal . '-' . $report['from'] . '-' . $report['until'] . '.csv"');
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end export()

	/**
	 * The report as of now.
	 *
	 * @param string $portal The portal slug.
	 * @param int $months How many full months.
	 *
	 * @return array<string, mixed>
	 */
	private function build(string $portal, int $months): array {
		return $this->report->forPortal(
			portal: $portal,
			now: DateTimeImmutable::createFromInterface($this->time->getDateTime()),
			months: $months
		);
	}//end build()

	/**
	 * Whether the slug names a published portal.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return bool
	 */
	private function isPublished(string $portal): bool {
		foreach ($this->portals->allPublishedPortals() as $site) {
			if (($site['slug'] ?? null) === $portal) {
				return true;
			}
		}

		return false;
	}//end isPublished()
}//end class
