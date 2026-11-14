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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Kernel\Contracts\TrafficEventStoreInterface;
use OCA\Portaliq\Kernel\Contracts\TrafficReportNumbersInterface;
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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */
class TrafficReportController extends Controller {

	/**
	 * The most days one export spans.
	 */
	public const MAX_DAYS = 366;

	/**
	 * The periods the summary answers, in days: exactly what the KPI
	 * cards on a portal's page offer. An empty period means 30.
	 */
	public const SUMMARY_DAYS = ['7', '30', '90', '365'];

	/**
	 * Constructor.
	 *
	 * @param string            $appName The app id.
	 * @param IRequest          $request The request.
	 * @param TrafficEventStoreInterface $store   Reads the daily records.
	 * @param TrafficExport     $export  Renders the file.
	 * @param TrafficReportNumbersInterface $numbers Folds the records into totals.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TrafficEventStoreInterface $store,
		private readonly TrafficExport $export,
		private readonly TrafficReportNumbersInterface $numbers,
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
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	#[NoCSRFRequired]
	public function export(string $portal = '', string $from = '', string $to = '', string $segment = '', string $format = 'csv'): Response {
		$export = new RecordsExport(portal: $portal, from: $from, to: $to, segment: $segment, format: $format);
		$reason = $this->refusal(export: $export);
		if ($reason !== null) {
			return new JSONResponse(['error' => $reason], Http::STATUS_BAD_REQUEST);
		}

		return $this->exportFile(export: $export);
	}//end export()


	/**
	 * The daily records as a file to download, never cached.
	 *
	 * @param RecordsExport $export A well-formed export request.
	 *
	 * @return Response
	 */
	private function exportFile(RecordsExport $export): Response {
		$records = $this->store->dailyBetween(portal: $export->portal, from: $export->from, to: $export->to, segment: $export->segment);
		$body = $this->export->csv(records: $records);
		$contentType = 'text/csv; charset=utf-8';
		if ($export->format === 'json') {
			$body = $this->export->json(records: $records);
			$contentType = 'application/json; charset=utf-8';
		}

		$response = new DataDisplayResponse($body, Http::STATUS_OK, ['Content-Type' => $contentType]);
		$fileName = $this->export->fileName(
			portal: $export->portal,
			from: $export->from,
			to: $export->to,
			segment: $export->segment,
			format: $export->format
		);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end exportFile()


	/**
	 * A portal's four headline totals over the last days, for the KPI
	 * cards on its detail page.
	 *
	 * Reads the "all visits" records only: a segment's record repeats
	 * visits the "all visits" record already counts. Folded by the same
	 * arithmetic as the scheduled report, so a card and a mail agree.
	 *
	 * @param string $portal The portal slug.
	 * @param string $days   7, 30, 90 or 365; '' for 30.
	 *
	 * @return JSONResponse The totals, or a 400 with a reason.
	 *
	 * @auth admin-only audience measurement is an operator's surface, the same posture as the export
	 *
	 * @spec openspec/specs/portal-traffic-kpi-cards/spec.md#requirement-the-summary-endpoint-must-return-a-portals-four-totals-for-a-period
	 */
	public function summary(string $portal = '', string $days = ''): JSONResponse {
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/', $portal) !== 1) {
			return new JSONResponse(['error' => 'missing-portal'], Http::STATUS_BAD_REQUEST);
		}

		if ($days === '') {
			$days = '30';
		}

		if (in_array($days, self::SUMMARY_DAYS, true) === false) {
			return new JSONResponse(['error' => 'invalid-period'], Http::STATUS_BAD_REQUEST);
		}

		return $this->totals(portal: $portal, span: (int)$days);
	}//end summary()


	/**
	 * A portal's four headline totals over the last days up to today, never
	 * cached.
	 *
	 * @param string $portal A well-formed portal slug.
	 * @param int    $span   How many days, today included.
	 *
	 * @return JSONResponse
	 */
	private function totals(string $portal, int $span): JSONResponse {
		$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
		$from = $today->modify('-'.($span - 1).' days')->format('Y-m-d');
		$to = $today->format('Y-m-d');
		$folded = $this->numbers->fold(records: $this->store->dailyBetween(portal: $portal, from: $from, to: $to));

		$response = new JSONResponse(
			[
				'portal' => $portal,
				'from' => $from,
				'to' => $to,
				'days' => $span,
				'pageViews' => $folded['pageViews'],
				'sessions' => $folded['sessions'],
				'visitors' => $folded['visitors'],
				'engagedSessions' => $folded['engagedSessions'],
			]
		);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end totals()


	/**
	 * Why the request is refused, or null.
	 *
	 * @param RecordsExport $export The export request.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(RecordsExport $export): ?string {
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/', $export->portal) !== 1) {
			return 'missing-portal';
		}

		return $this->rangeRefusal(export: $export) ?? $this->shapeRefusal(export: $export);
	}//end refusal()


	/**
	 * Why the days are refused: not two days in order, or too many; null when
	 * they fit.
	 *
	 * @param RecordsExport $export The export request.
	 *
	 * @return string|null The reason.
	 */
	private function rangeRefusal(RecordsExport $export): ?string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $export->from) !== 1
			|| preg_match('/^\d{4}-\d{2}-\d{2}$/', $export->to) !== 1
			|| $export->from > $export->to
		) {
			return 'invalid-range';
		}

		if ((strtotime($export->to) - strtotime($export->from)) / 86400 >= self::MAX_DAYS) {
			return 'range-too-long';
		}

		return null;
	}//end rangeRefusal()


	/**
	 * Why the segment or the format is refused, or null.
	 *
	 * @param RecordsExport $export The export request.
	 *
	 * @return string|null The reason.
	 */
	private function shapeRefusal(RecordsExport $export): ?string {
		if ($export->segment !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $export->segment) !== 1) {
			return 'invalid-segment';
		}

		if (in_array($export->format, TrafficExport::FORMATS, true) === false) {
			return 'invalid-format';
		}

		return null;
	}//end shapeRefusal()
}//end class
