<?php

/**
 * Unit tests for TrafficServerBatch.
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

use OCA\Portaliq\Service\Traffic\TrafficServerBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A server-side batch regrouped by the visitor each event belongs to.
 *
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */
#[CoversClass(TrafficServerBatch::class)]
class TrafficServerBatchTest extends TestCase {

	/**
	 * Events without their own address and agent fall into one group under the batch's.
	 *
	 * @return void
	 */
	public function testEventsWithoutTheirOwnVisitorShareTheBatchDefaults(): void {
		$groups = (new TrafficServerBatch())->byVisitor(batch: [
			'remoteAddress' => '10.0.0.1',
			'userAgent' => 'Batch/1.0',
			'events' => [['name' => 'page_view'], ['name' => 'click']],
		]);

		$this->assertSame(
			expected: [['ip' => '10.0.0.1', 'userAgent' => 'Batch/1.0', 'events' => [['name' => 'page_view'], ['name' => 'click']]]],
			actual: $groups
		);
	}

	/**
	 * An event's own address and agent win, are stripped from the event, and group it with its peers.
	 *
	 * @return void
	 */
	public function testAnEventsOwnVisitorWinsAndIsStripped(): void {
		$groups = (new TrafficServerBatch())->byVisitor(batch: [
			'remoteAddress' => '10.0.0.1',
			'userAgent' => 'Batch/1.0',
			'events' => [
				'first' => ['name' => 'a', 'remoteAddress' => '192.0.2.7', 'userAgent' => 'Phone/2'],
				'second' => ['name' => 'b'],
				'third' => ['name' => 'c', 'remoteAddress' => '192.0.2.7', 'userAgent' => 'Phone/2'],
				'fourth' => ['name' => 'd', 'remoteAddress' => '192.0.2.7'],
			],
		]);

		$this->assertSame(
			expected: [
				['ip' => '192.0.2.7', 'userAgent' => 'Phone/2', 'events' => [['name' => 'a'], ['name' => 'c']]],
				['ip' => '10.0.0.1', 'userAgent' => 'Batch/1.0', 'events' => [['name' => 'b']]],
				['ip' => '192.0.2.7', 'userAgent' => 'Batch/1.0', 'events' => [['name' => 'd']]],
			],
			actual: $groups
		);
	}

	/**
	 * A non-array event is kept as it is under the batch's visitor; a batch without events gives no groups.
	 *
	 * @return void
	 */
	public function testScalarEventsKeepTheDefaultsAndNoEventsGiveNoGroups(): void {
		$batch = new TrafficServerBatch();

		$this->assertSame(
			expected: [['ip' => '', 'userAgent' => '', 'events' => ['raw', 7]]],
			actual: $batch->byVisitor(batch: ['events' => ['raw', 7]])
		);
		$this->assertSame(expected: [], actual: $batch->byVisitor(batch: ['remoteAddress' => '10.0.0.1']));
	}
}
