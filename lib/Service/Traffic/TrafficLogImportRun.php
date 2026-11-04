<?php

/**
 * Portaliq Traffic Log Import Run.
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

use OCA\Portaliq\Service\TrafficEventValidator;

/**
 * The state of one access-log import: the running outcome, the lines
 * already seen, and the page views waiting per visitor.
 *
 * A visitor is the log's address and user agent joined by a NUL byte.
 *
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */
final class TrafficLogImportRun {

	/**
	 * The running outcome.
	 *
	 * @var array{lines: int, views: int, skipped: int, duplicates: int, accepted: int, refused: array<string, int>}
	 */
	public array $outcome = ['lines' => 0, 'views' => 0, 'skipped' => 0, 'duplicates' => 0, 'accepted' => 0, 'refused' => []];

	/**
	 * The identity of every line counted so far.
	 *
	 * @var array<string, true>
	 */
	private array $seen = [];

	/**
	 * The page views not yet handed on, per visitor.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $batches = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $portal The portal record the log belongs to.
	 * @param string               $origin The site's origin without a trailing slash.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $portal,
		private readonly string $origin,
	) {
	}

	/**
	 * Count one parsed line and queue its page view.
	 *
	 * @param array<string, string>|null $view The parsed line, or null for a line that is not a page view.
	 *
	 * @return string|null The visitor whose batch is now full, or null.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function add(?array $view): ?string {
		$this->outcome['lines']++;
		if ($view === null) {
			$this->outcome['skipped']++;
			return null;
		}

		$key = implode("\0", [$this->portal['slug'], substr($view['timestamp'], 0, 19), $view['path'], $view['ip'], $view['userAgent']]);
		if (isset($this->seen[$key]) === true) {
			$this->outcome['duplicates']++;
			return null;
		}

		$this->seen[$key] = true;
		$this->outcome['views']++;
		return $this->queue(view: $view);
	}

	/**
	 * Take a visitor's queued page views off the queue.
	 *
	 * @param string $visitor The visitor.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function take(string $visitor): array {
		$events = ($this->batches[$visitor] ?? []);
		unset($this->batches[$visitor]);

		return $events;
	}

	/**
	 * The visitors that still have page views queued, in queue order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function waiting(): array {
		return array_map('strval', array_keys($this->batches));
	}

	/**
	 * Add what the ingest step answered for one batch to the outcome.
	 *
	 * @param array{accepted: int|string, refused: array<string, int|string>} $result The ingest answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function count(array $result): void {
		$this->outcome['accepted'] += (int)$result['accepted'];
		foreach ($result['refused'] as $reason => $count) {
			$this->outcome['refused'][$reason] = ($this->outcome['refused'][$reason] ?? 0) + (int)$count;
		}
	}

	/**
	 * Queue a new page view under its visitor.
	 *
	 * @param array<string, string> $view The parsed line.
	 *
	 * @return string|null The visitor when its batch is now full, or null.
	 */
	private function queue(array $view): ?string {
		$visitor = $view['ip'] . "\0" . $view['userAgent'];
		$this->batches[$visitor][] = [
			'name' => 'page_view',
			'timestamp' => $view['timestamp'],
			'sequence' => count($this->batches[$visitor] ?? []),
			'pageLocation' => $this->origin . $view['path'],
			'pageReferrer' => $view['referrer'],
			'params' => ['import' => 'log'],
		];
		if (count($this->batches[$visitor]) >= TrafficEventValidator::MAX_BATCH) {
			return $visitor;
		}

		return null;
	}
}
