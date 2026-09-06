<?php

/**
 * Portaliq Traffic Metrics.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The collector's counters: how many events were accepted, and how many were
 * refused under which reason.
 *
 * KEPT IN APP CONFIG, NOT IN MEMORY. A PHP process lives for one request, so
 * an in-memory counter would read zero on every scrape. App config is the
 * cheapest durable integer store the platform offers; the increment is not
 * atomic, and for an operational counter that is acceptable: a lost
 * increment under concurrency is a slightly low number, not a wrong
 * decision.
 *
 * The counters are what make a refusal VISIBLE. Without them a misconfigured
 * client and a working one both produce a 204 and a quiet dashboard.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */
class TrafficMetrics {

	/**
	 * The app config key holding the accepted total.
	 */
	public const ACCEPTED_KEY = 'traffic_accepted_total';

	/**
	 * The prefix of the per-reason refused counters.
	 */
	public const REFUSED_PREFIX = 'traffic_refused_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config The app config store.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	/**
	 * Count accepted events.
	 *
	 * @param int $count How many.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function accepted(int $count): void {
		if ($count <= 0) {
			return;
		}

		$this->add(key: self::ACCEPTED_KEY, count: $count);
	}

	/**
	 * Count refused events under their reasons.
	 *
	 * @param array<string, int> $reasons Reason => count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function refused(array $reasons): void {
		foreach ($reasons as $reason => $count) {
			if ($count <= 0 || preg_match('/^[a-z0-9-]{1,64}$/', $reason) !== 1) {
				continue;
			}

			$this->add(key: self::REFUSED_PREFIX . $reason, count: $count);
		}
	}

	/**
	 * The accepted total.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function acceptedTotal(): int {
		return $this->config->getValueInt(Application::APP_ID, self::ACCEPTED_KEY, 0);
	}

	/**
	 * Every refusal reason seen so far, with its count.
	 *
	 * @return array<string, int> Reason => count, sorted by reason.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function refusedByReason(): array {
		$out = [];
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			if (str_starts_with($key, self::REFUSED_PREFIX) === false) {
				continue;
			}

			$out[substr($key, strlen(self::REFUSED_PREFIX))] = $this->config->getValueInt(Application::APP_ID, $key, 0);
		}

		ksort($out);

		return $out;
	}

	/**
	 * Add to one counter.
	 *
	 * @param string $key   The config key.
	 * @param int    $count The increment.
	 *
	 * @return void
	 */
	private function add(string $key, int $count): void {
		$current = $this->config->getValueInt(Application::APP_ID, $key, 0);
		$this->config->setValueInt(Application::APP_ID, $key, $current + $count);
	}
}
