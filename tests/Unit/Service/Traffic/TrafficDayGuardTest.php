<?php

/**
 * Unit tests for TrafficDayGuard.
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

use OCA\Portaliq\Service\Traffic\TrafficDayGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A rebuilt day that counts less than the stored one lost events to the purge.
 *
 * @spec openspec/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */
#[CoversClass(TrafficDayGuard::class)]
class TrafficDayGuardTest extends TestCase {

	/**
	 * The rebuilt and stored records, and whether the rebuild lost events.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: bool}>
	 */
	public static function records(): array {
		return [
			'nothing stored' => [['pageViews' => 0], null, false],
			'fewer page views' => [['pageViews' => 4, 'events' => ['click' => 9]], ['pageViews' => 5, 'events' => ['click' => 1]], true],
			'fewer events' => [
				['pageViews' => 5, 'events' => ['click' => 2, 'scroll' => '1']],
				['pageViews' => 5, 'events' => ['click' => 2, 'scroll' => 2]],
				true,
			],
			'same counts' => [['pageViews' => 5, 'events' => ['click' => 3]], ['pageViews' => 5, 'events' => ['click' => 1, 'scroll' => 2]], false],
			'more of both' => [['pageViews' => 6, 'events' => ['click' => 4]], ['pageViews' => 5, 'events' => ['click' => 3]], false],
			'events not a map' => [['pageViews' => 5, 'events' => 'broken'], ['pageViews' => 5, 'events' => ['click' => 1]], true],
			'both without fields' => [[], [], false],
		];
	}

	/**
	 * @param array<string, mixed>      $record   The rebuilt record.
	 * @param array<string, mixed>|null $stored   The stored record.
	 * @param bool                      $expected Whether events were lost.
	 *
	 * @return void
	 */
	#[DataProvider('records')]
	public function testLostEvents(array $record, ?array $stored, bool $expected): void {
		$this->assertSame(expected: $expected, actual: (new TrafficDayGuard())->lostEvents(record: $record, stored: $stored));
	}
}
