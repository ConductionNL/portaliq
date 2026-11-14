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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Kernel\Contracts\TrafficLogImporterInterface;
use OCA\Portaliq\Service\PortalBySlug;
use OCA\Portaliq\Service\PublishedPortals;
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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */
class TrafficLogImporter implements TrafficLogImporterInterface {
	use PortalBySlug;

	/**
	 * Constructor.
	 *
	 * @param PublishedPortals       $portals Finds the portal by slug.
	 * @param TrafficLogParser     $parser  Reads a line.
	 * @param TrafficIngestService $ingest  Stores the views.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublishedPortals $portals,
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
	 * @return array{lines: int, views: int, skipped: int, duplicates: int, accepted: int,
	 *               refused: array<string, int>}|null The outcome, or null for an unknown portal.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function import(string $slug, $stream, string $format, string $host): ?array {
		$portal = $this->portalBySlug(slug: $slug);
		if ($portal === null) {
			return null;
		}

		$run = new TrafficLogImportRun(portal: $portal, origin: rtrim($host, '/'));
		while (($line = fgets($stream)) !== false) {
			$full = $run->add(view: $this->parser->parse(line: $line, format: $format));
			if ($full !== null) {
				$this->flush(run: $run, visitor: $full);
			}
		}

		foreach ($run->waiting() as $visitor) {
			$this->flush(run: $run, visitor: $visitor);
		}

		return $run->outcome;
	}

	/**
	 * Hand one visitor's queued views to the ingest service.
	 *
	 * @param TrafficLogImportRun $run     The import, whose outcome is updated.
	 * @param string              $visitor The address and agent, joined.
	 *
	 * @return void
	 */
	private function flush(TrafficLogImportRun $run, string $visitor): void {
		[$address, $agent] = explode("\0", $visitor, 2) + ['', ''];
		$run->count(
			result: $this->ingest->ingestForPortal(
				portal: $run->portal,
				events: $run->take(visitor: $visitor),
				context: ['ip' => $address, 'userAgent' => $agent, 'consent' => false, 'serverSide' => false, 'allowOld' => true]
			)
		);
	}
}
