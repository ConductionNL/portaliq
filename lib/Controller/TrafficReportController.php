<?php

/**
 * Portaliq Traffic Report Controller
 *
 * The export of the daily records (portal-traffic-reporting): one file,
 * CSV or JSON, for a portal, a span of days and a segment.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficExport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * ADMIN ONLY, BY OMISSION. No `NoAdminRequired` on the action: the daily
 * records are the portal's audience measurement and the export is an
 * operator's surface, the same posture as the Reports page it hangs off.
 * `NoCSRFRequired` because a download is a top-level GET the browser
 * navigates to, which carries no request token; it reads and changes
 * nothing, and the session cookie's SameSite guard is what a cross-site
 * GET meets.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */
class TrafficReportController extends Controller {

	/**
	 * The most days one export spans.
	 */
	public const MAX_DAYS = 366;

	/**
	 * Constructor.
	 *
	 * @param string            $appName The app id.
	 * @param IRequest          $request The request.
	 * @param TrafficEventStore $store   Reads the daily records.
	 * @param TrafficExport     $export  Renders the file.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TrafficEventStore $store,
		private readonly TrafficExport $export,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * Download the daily records.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day, YYYY-MM-DD.
	 * @param string $to      The last day, YYYY-MM-DD.
	 * @param string $segment The segment id, '' for all sessions.
	 * @param string $format  `csv` or `json`.
	 *
	 * @return Response The file, or a 400 with a reason.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	#[NoCSRFRequired]
	public function export(string $portal = '', string $from = '', string $to = '', string $segment = '', string $format = 'csv'): Response {
		$reason = $this->refusal(portal: $portal, from: $from, to: $to, segment: $segment, format: $format);
		if ($reason !== null) {
			return new JSONResponse(['error' => $reason], Http::STATUS_BAD_REQUEST);
		}

		$records = $this->store->dailyBetween(portal: $portal, from: $from, to: $to, segment: $segment);
		$body = $this->export->csv(records: $records);
		$contentType = 'text/csv; charset=utf-8';
		if ($format === 'json') {
			$body = $this->export->json(records: $records);
			$contentType = 'application/json; charset=utf-8';
		}

		$response = new DataDisplayResponse($body, Http::STATUS_OK, ['Content-Type' => $contentType]);
		$response->addHeader(
			'Content-Disposition',
			'attachment; filename="' . $this->export->fileName(portal: $portal, from: $from, to: $to, segment: $segment, format: $format) . '"'
		);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end export()


	/**
	 * Why the request is refused, or null.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day.
	 * @param string $to      The last day.
	 * @param string $segment The segment id.
	 * @param string $format  The format.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(string $portal, string $from, string $to, string $segment, string $format): ?string {
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,127}$/', $portal) !== 1) {
			return 'missing-portal';
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $from > $to) {
			return 'invalid-range';
		}

		if ((strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
			return 'range-too-long';
		}

		if ($segment !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $segment) !== 1) {
			return 'invalid-segment';
		}

		if (in_array($format, TrafficExport::FORMATS, true) === false) {
			return 'invalid-format';
		}

		return null;
	}//end refusal()
}//end class
