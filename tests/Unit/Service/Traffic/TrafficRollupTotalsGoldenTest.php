<?php

/**
 * TrafficRollupTotalsGoldenTest
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

use OCA\Portaliq\Service\Traffic\TrafficRollup;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The session-level totals of a roll-up, pinned per session set: views,
 * visitors and their type, accounts, engagement, seconds, event counts and
 * the last event.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficRollup
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperiments
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficFunnels
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficGoals
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficJourneyStats
 */
class TrafficRollupTotalsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-rollup-totals.json';

	/**
	 * Every session set's totals match the recorded ones.
	 *
	 * @return void
	 */
	public function testEveryTotalsMatchesTheGolden(): void {
		$totals = new ReflectionMethod(TrafficRollup::class, 'totals');
		$rollup = new TrafficRollup();
		$actual = [];
		foreach ($this->sessionSets() as $name => $sessions) {
			$actual[$name] = $totals->invoke($rollup, $sessions);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryTotalsMatchesTheGolden()

	/**
	 * An event.
	 *
	 * @param string               $name  The event name.
	 * @param float                $at    Seconds into the day.
	 * @param array<string, mixed> $extra More fields.
	 *
	 * @return array<string, mixed>
	 */
	private function event(string $name, float $at, array $extra = []): array {
		return ['name' => $name, '_at' => $at, 'occurredAt' => sprintf('2026-09-04T10:%02d:%02d.000Z', intdiv((int)$at, 60), ((int)$at % 60))] + $extra;
	}//end event()

	/**
	 * Session sets by name.
	 *
	 * @return array<string, array<int|string, array<string, mixed>>>
	 */
	private function sessionSets(): array {
		return [
			'none'                 => [],
			'one view'             => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0)]]],
			'two views engaged'    => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0), $this->event(name: 'page_view', at: 3)]]],
			'scroll engaged'       => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0), $this->event(name: 'scroll', at: 2)]]],
			'heat scroll only'     => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0), $this->event(name: 'heat_scroll', at: 2)]]],
			'long stay engaged'    => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0), $this->event(name: 'click', at: 10)]]],
			'just short'           => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0), $this->event(name: 'click', at: 9.5)]]],
			'backwards clock'      => [['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 20), $this->event(name: 'click', at: 5)]]],
			'accounts'             => [
				['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0, extra: ['userRef' => ' u-1 ']), $this->event(name: 'click', at: 1, extra: ['userRef' => 'u-1'])]],
				['visitor' => 'h2', 'events' => [$this->event(name: 'page_view', at: 0, extra: ['userRef' => '']), $this->event(name: 'click', at: 1, extra: ['userRef' => 'u-2'])]],
			],
			'visitor types'        => [
				'a' => ['visitor' => 'c:1', 'events' => [$this->event(name: 'session_start', at: 0, extra: ['params' => ['visitorType' => 'returning']])]],
				'b' => ['visitor' => 'c:1', 'events' => [$this->event(name: 'session_start', at: 30, extra: ['params' => ['first' => true]])]],
				'c' => ['visitor' => 'c:1', 'events' => [$this->event(name: 'session_start', at: 60, extra: ['params' => ['visitorType' => 'returning']])]],
				'd' => ['visitor' => 'c:2', 'events' => [$this->event(name: 'session_start', at: 0, extra: ['params' => ['visitorType' => 'returning']])]],
				'e' => ['visitor' => 'c:3', 'events' => [$this->event(name: 'page_view', at: 0)]],
				'f' => ['visitor' => 'h4', 'events' => [$this->event(name: 'session_start', at: 0, extra: ['params' => ['visitorType' => 'new']])]],
				'g' => ['visitor' => 'c:5', 'events' => [$this->event(name: 'session_start', at: 0, extra: ['params' => ['visitorType' => 'new']])]],
			],
			'returning then new'   => [
				['visitor' => 'c:9', 'events' => [$this->event(name: 'session_start', at: 0, extra: ['params' => ['visitorType' => 'returning']])]],
				['visitor' => 'c:9', 'events' => [$this->event(name: 'session_start', at: 9, extra: ['params' => ['visitorType' => 'new']])]],
			],
			'returning, unknown'   => [
				['visitor' => 'c:7', 'events' => [$this->event(name: 'session_start', at: 0, extra: ['params' => ['visitorType' => 'returning']])]],
				['visitor' => 'c:7', 'events' => [$this->event(name: 'page_view', at: 9)]],
			],
			'many names, last'     => [
				['visitor' => 'h1', 'events' => [$this->event(name: 'page_view', at: 0), $this->event(name: 'form_start', at: 4), $this->event(name: 'page_view', at: 70)]],
				['visitor' => 'h2', 'events' => [$this->event(name: 'zeta', at: 0), $this->event(name: 'alpha', at: 1), $this->event(name: 'page_view', at: 2, extra: ['occurredAt' => ''])]],
				['visitor' => 'h1', 'events' => [['name' => 'ping', '_at' => 0.0]]],
			],
		];
	}//end sessionSets()
}//end class
