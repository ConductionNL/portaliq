<?php

/**
 * Unit tests for TrafficHeatmapStats.
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

use OCA\Portaliq\Service\Traffic\TrafficHeatmapStats;
use PHPUnit\Framework\TestCase;

/**
 * Clicks land on the grid, scroll depths in their decile, and nothing
 * that is not a position is counted.
 */
class TrafficHeatmapStatsTest extends TestCase {

	/**
	 * One heat event.
	 *
	 * @param string               $name   heat_click or heat_scroll.
	 * @param string               $path   pagePath.
	 * @param array<string, mixed> $params The params.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $name, string $path, array $params): array {
		return ['name' => $name, 'pagePath' => $path, 'params' => $params];
	}//end event()


	/**
	 * Two clicks in the same two-percent cell are one cell with count 2;
	 * a fraction of 1 lands in the last cell, not past it.
	 *
	 * @return void
	 */
	public function testClicksAreBucketedOnAFiftyByFiftyGrid(): void {
		$rows = (new TrafficHeatmapStats())->rows(sessions: [['events' => [
			$this->event('heat_click', '/', ['x' => 0.25, 'y' => 0.061]),
			$this->event('heat_click', '/', ['x' => 0.259, 'y' => 0.079]),
			$this->event('heat_click', '/', ['x' => 1, 'y' => 1.0]),
			$this->event('page_view', '/', []),
		]]]);

		$this->assertCount(1, $rows);
		$this->assertSame('/', $rows[0]['path']);
		$this->assertSame(3, $rows[0]['samples']);
		$this->assertSame([['x' => 12, 'y' => 3, 'count' => 2], ['x' => 49, 'y' => 49, 'count' => 1]], $rows[0]['clicks']);
		$this->assertSame(array_fill(0, 10, 0), $rows[0]['scroll']);
	}//end testClicksAreBucketedOnAFiftyByFiftyGrid()


	/**
	 * Scroll depths fall into deciles, a full scroll into the last one.
	 *
	 * @return void
	 */
	public function testScrollDepthsFallIntoDeciles(): void {
		$rows = (new TrafficHeatmapStats())->rows(sessions: [['events' => [
			$this->event('heat_scroll', '/a', ['depth' => 0.0]),
			$this->event('heat_scroll', '/a', ['depth' => 0.45]),
			$this->event('heat_scroll', '/a', ['depth' => 0.99]),
			$this->event('heat_scroll', '/a', ['depth' => 1]),
		]]]);

		$this->assertSame([1, 0, 0, 0, 1, 0, 0, 0, 0, 2], $rows[0]['scroll']);
		$this->assertSame(4, $rows[0]['samples']);
	}//end testScrollDepthsFallIntoDeciles()


	/**
	 * A value that is not a fraction of the page is not a position and
	 * is not counted; pages rank by samples.
	 *
	 * @return void
	 */
	public function testOnlyPositionsCountAndPagesRankBySamples(): void {
		$rows = (new TrafficHeatmapStats())->rows(sessions: [['events' => [
			$this->event('heat_click', '/quiet', ['x' => 0.5, 'y' => 0.5]),
			$this->event('heat_click', '/quiet', ['x' => 1.5, 'y' => 0.5]),
			$this->event('heat_click', '/quiet', ['x' => 'left', 'y' => 0.5]),
			$this->event('heat_scroll', '/quiet', ['depth' => -0.1]),
			$this->event('heat_click', '/busy', ['x' => 0.1, 'y' => 0.1]),
			$this->event('heat_click', '/busy', ['x' => 0.2, 'y' => 0.2]),
			$this->event('heat_click', '', ['x' => 0.2, 'y' => 0.2]),
		]]]);

		$this->assertSame(['/busy', '/quiet'], array_column($rows, 'path'));
		$this->assertSame(1, $rows[1]['samples'], 'three of the four quiet events were not positions');
	}//end testOnlyPositionsCountAndPagesRankBySamples()
}//end class
