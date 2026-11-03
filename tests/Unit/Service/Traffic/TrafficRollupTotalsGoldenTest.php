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
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame($case, $actual[$name], $name);
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
			'one view'             => [['visitor' => 'h1', 'events' => [$this->event('page_view', 0)]]],
			'two views engaged'    => [['visitor' => 'h1', 'events' => [$this->event('page_view', 0), $this->event('page_view', 3)]]],
			'scroll engaged'       => [['visitor' => 'h1', 'events' => [$this->event('page_view', 0), $this->event('scroll', 2)]]],
			'heat scroll only'     => [['visitor' => 'h1', 'events' => [$this->event('page_view', 0), $this->event('heat_scroll', 2)]]],
			'long stay engaged'    => [['visitor' => 'h1', 'events' => [$this->event('page_view', 0), $this->event('click', 10)]]],
			'just short'           => [['visitor' => 'h1', 'events' => [$this->event('page_view', 0), $this->event('click', 9.5)]]],
			'backwards clock'      => [['visitor' => 'h1', 'events' => [$this->event('page_view', 20), $this->event('click', 5)]]],
			'accounts'             => [
				['visitor' => 'h1', 'events' => [$this->event('page_view', 0, ['userRef' => ' u-1 ']), $this->event('click', 1, ['userRef' => 'u-1'])]],
				['visitor' => 'h2', 'events' => [$this->event('page_view', 0, ['userRef' => '']), $this->event('click', 1, ['userRef' => 'u-2'])]],
			],
			'visitor types'        => [
				'a' => ['visitor' => 'c:1', 'events' => [$this->event('session_start', 0, ['params' => ['visitorType' => 'returning']])]],
				'b' => ['visitor' => 'c:1', 'events' => [$this->event('session_start', 30, ['params' => ['first' => true]])]],
				'c' => ['visitor' => 'c:1', 'events' => [$this->event('session_start', 60, ['params' => ['visitorType' => 'returning']])]],
				'd' => ['visitor' => 'c:2', 'events' => [$this->event('session_start', 0, ['params' => ['visitorType' => 'returning']])]],
				'e' => ['visitor' => 'c:3', 'events' => [$this->event('page_view', 0)]],
				'f' => ['visitor' => 'h4', 'events' => [$this->event('session_start', 0, ['params' => ['visitorType' => 'new']])]],
				'g' => ['visitor' => 'c:5', 'events' => [$this->event('session_start', 0, ['params' => ['visitorType' => 'new']])]],
			],
			'returning then new'   => [
				['visitor' => 'c:9', 'events' => [$this->event('session_start', 0, ['params' => ['visitorType' => 'returning']])]],
				['visitor' => 'c:9', 'events' => [$this->event('session_start', 9, ['params' => ['visitorType' => 'new']])]],
			],
			'many names, last'     => [
				['visitor' => 'h1', 'events' => [$this->event('page_view', 0), $this->event('form_start', 4), $this->event('page_view', 70)]],
				['visitor' => 'h2', 'events' => [$this->event('zeta', 0), $this->event('alpha', 1), $this->event('page_view', 2, ['occurredAt' => ''])]],
				['visitor' => 'h1', 'events' => [['name' => 'ping', '_at' => 0.0]]],
			],
		];
	}//end sessionSets()
}//end class
