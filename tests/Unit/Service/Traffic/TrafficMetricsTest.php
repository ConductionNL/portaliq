<?php

/**
 * Unit tests for TrafficMetrics.
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use PHPUnit\Framework\TestCase;

// The test tree is not autoloaded; the double is a plain include.
require_once __DIR__ . '/FakeAppConfig.php';

/**
 * The counters that make a refusal visible.
 */
class TrafficMetricsTest extends TestCase {


	/**
	 * Counts accumulate across calls, per reason, and read back sorted.
	 *
	 * @return void
	 */
	public function testCountsAccumulatePerReason(): void {
		$metrics = new TrafficMetrics((new FakeAppConfig())->mock($this));

		$metrics->accepted(count: 3);
		$metrics->accepted(count: 2);
		$metrics->refused(reasons: ['event-not-enabled' => 2, 'bot' => 1]);
		$metrics->refused(reasons: ['event-not-enabled' => 1]);

		$this->assertSame(5, $metrics->acceptedTotal());
		$this->assertSame(['bot' => 1, 'event-not-enabled' => 3], $metrics->refusedByReason());
	}//end testCountsAccumulatePerReason()


	/**
	 * A reason that is not a safe label is not written.
	 *
	 * The reason becomes a Prometheus label and an app config key; a value
	 * from the wire must not be able to become either.
	 *
	 * @return void
	 */
	public function testAnUnsafeReasonIsNotCounted(): void {
		$fake = new FakeAppConfig();
		$metrics = new TrafficMetrics($fake->mock($this));

		$metrics->refused(reasons: ['weird"label' => 1, 'ok' => 1, 'zero' => 0]);

		$this->assertSame(['ok' => 1], $metrics->refusedByReason());
	}//end testAnUnsafeReasonIsNotCounted()


}//end class
