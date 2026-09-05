<?php

/**
 * Portaliq Traffic Log Importer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\TrafficEventValidator;
use OCA\Portaliq\Service\TrafficIngestService;

/**
 * Turns an access log into page views through the same ingest step a
 * browser's beacon takes.
 *
 * The log's address and user agent are handed to the ingest service as
 * the request context, one visitor at a time, so the visitor hash, the
 * device family and the region are derived exactly as they would be for
 * a live visitor, and the address is dropped in the same call. Nothing
 * of the log is kept beyond what a beacon would have left.
 *
 * IDEMPOTENT WITHIN THE IMPORT. A line is identified by its portal,
 * second, path, address and agent; a log that was concatenated from two
 * rotations repeats lines, and each is counted once. Across imports the
 * aggregation is idempotent by day but the raw events are not deduplicated,
 * so importing the same file twice doubles its views; the command says so.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */
class TrafficLogImporter {

	/**
	 * Constructor.
	 *
	 * @param PortalResolver       $portals Finds the portal by slug.
	 * @param TrafficLogParser     $parser  Reads a line.
	 * @param TrafficIngestService $ingest  Stores the views.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficLogParser $parser,
		private readonly TrafficIngestService $ingest,
	) {
	}

	/**
	 * Import every line of a stream.
	 *
	 * @param string   $slug   The portal slug.
	 * @param resource $stream The open log, read line by line.
	 * @param string   $format `combined` or `json`.
	 * @param string   $host   The site's origin (`https://example.org`), for the page location.
	 *
	 * @return array{lines: int, views: int, skipped: int, duplicates: int, accepted: int, refused: array<string, int>}|null The outcome, or null for an unknown portal.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function import(string $slug, $stream, string $format, string $host): ?array {
		$portal = null;
		foreach ($this->portals->allPublishedPortals() as $candidate) {
			if (($candidate['slug'] ?? null) === $slug) {
				$portal = $candidate;
				break;
			}
		}

		if ($portal === null) {
			return null;
		}

		$origin = rtrim($host, '/');
		$outcome = ['lines' => 0, 'views' => 0, 'skipped' => 0, 'duplicates' => 0, 'accepted' => 0, 'refused' => []];
		$seen = [];
		$batches = [];
		while (($line = fgets($stream)) !== false) {
			$outcome['lines']++;
			$view = $this->parser->parse(line: $line, format: $format);
			if ($view === null) {
				$outcome['skipped']++;
				continue;
			}

			$key = implode("\0", [$slug, substr($view['timestamp'], 0, 19), $view['path'], $view['ip'], $view['userAgent']]);
			if (isset($seen[$key]) === true) {
				$outcome['duplicates']++;
				continue;
			}

			$seen[$key] = true;
			$outcome['views']++;
			$visitor = $view['ip'] . "\0" . $view['userAgent'];
			$batches[$visitor][] = [
				'name' => 'page_view',
				'timestamp' => $view['timestamp'],
				'sequence' => count($batches[$visitor] ?? []),
				'pageLocation' => $origin . $view['path'],
				'pageReferrer' => $view['referrer'],
				'params' => ['import' => 'log'],
			];
			if (count($batches[$visitor]) >= TrafficEventValidator::MAX_BATCH) {
				$this->flush(portal: $portal, visitor: $visitor, events: $batches[$visitor], outcome: $outcome);
				unset($batches[$visitor]);
			}
		}

		foreach ($batches as $visitor => $events) {
			$this->flush(portal: $portal, visitor: (string)$visitor, events: $events, outcome: $outcome);
		}

		return $outcome;
	}

	/**
	 * Hand one visitor's views to the ingest service.
	 *
	 * @param array<string, mixed>             $portal  The portal record.
	 * @param string                           $visitor The address and agent, joined.
	 * @param array<int, array<string, mixed>> $events  The views.
	 * @param array<string, mixed>             $outcome The running outcome, updated in place.
	 *
	 * @return void
	 */
	private function flush(array $portal, string $visitor, array $events, array &$outcome): void {
		[$ip, $agent] = explode("\0", $visitor, 2) + ['', ''];
		$result = $this->ingest->ingestForPortal(
			portal: $portal,
			events: $events,
			context: ['ip' => $ip, 'userAgent' => $agent, 'consent' => false, 'serverSide' => false, 'allowOld' => true]
		);
		$outcome['accepted'] += (int)$result['accepted'];
		foreach ($result['refused'] as $reason => $count) {
			$outcome['refused'][$reason] = ($outcome['refused'][$reason] ?? 0) + (int)$count;
		}
	}
}
