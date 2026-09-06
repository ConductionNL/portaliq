<?php

/**
 * Portaliq Traffic Heatmap Stats.
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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The heatmap of a day (portal-traffic-experiments): per page path, the
 * clicks bucketed on a fifty by fifty grid and the scroll depth as a
 * histogram of deciles.
 *
 * A click arrives as two fractions of the document (`x`, `y`), never as
 * pixels, so a page seen on a phone and on a desk lands on the same grid.
 * The grid is the resolution on purpose: a cell is two percent of the
 * page, coarse enough that no cell is one visitor's fingerprint and fine
 * enough to see which button nobody finds. What was clicked is the tag
 * and a short selector without ids; the text under the pointer is never
 * sent and cannot be counted here.
 *
 * Pure: sessions in, rows out.
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
 */
class TrafficHeatmapStats {

	/**
	 * The click event and the scroll depth event.
	 */
	public const CLICK = 'heat_click';

	/**
	 * The scroll depth event.
	 */
	public const SCROLL = 'heat_scroll';

	/**
	 * Cells per side of the click grid.
	 */
	public const GRID = 50;

	/**
	 * The scroll depth buckets: deciles.
	 */
	public const DECILES = 10;

	/**
	 * The most pages kept, ranked by samples.
	 */
	private const TOP = 50;

	/**
	 * The heatmap rows of the day's sessions, ranked by samples.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array<int, array{path: string, samples: int, clicks: array<int, array{x: int, y: int, count: int}>, scroll: array<int, int>}> The rows.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
	 */
	public function rows(array $sessions): array {
		$pages = [];
		foreach ($sessions as $session) {
			foreach (($session['events'] ?? []) as $event) {
				if (is_array($event) === false) {
					continue;
				}

				$name = (string)($event['name'] ?? '');
				if ($name !== self::CLICK && $name !== self::SCROLL) {
					continue;
				}

				$path = trim((string)($event['pagePath'] ?? ''));
				if ($path === '') {
					continue;
				}

				$pages[$path] ??= ['path' => $path, 'samples' => 0, 'cells' => [], 'scroll' => array_fill(0, self::DECILES, 0)];
				$pages[$path] = $this->add(page: $pages[$path], name: $name, params: $this->params(event: $event));
			}
		}

		$out = [];
		foreach ($pages as $page) {
			$out[] = $this->finish(page: $page);
		}

		usort($out, static fn (array $a, array $b): int => [$b['samples'], $a['path']] <=> [$a['samples'], $b['path']]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * Count one event into a page.
	 *
	 * @param array<string, mixed> $page   The page accumulator.
	 * @param string               $name   The event name.
	 * @param array<string, mixed> $params The event's params.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function add(array $page, string $name, array $params): array {
		if ($name === self::CLICK) {
			$column = $this->cell(value: ($params['x'] ?? null));
			$row = $this->cell(value: ($params['y'] ?? null));
			if ($column === null || $row === null) {
				return $page;
			}

			$key = $column . ':' . $row;
			$page['cells'][$key] = ($page['cells'][$key] ?? 0) + 1;
			$page['samples']++;

			return $page;
		}

		$depth = $this->fraction(value: ($params['depth'] ?? null));
		if ($depth === null) {
			return $page;
		}

		$page['scroll'][min(self::DECILES - 1, (int)floor($depth * self::DECILES))]++;
		$page['samples']++;

		return $page;
	}

	/**
	 * The accumulator as the rollup row: the cells as a list, sorted by
	 * count.
	 *
	 * @param array<string, mixed> $page The accumulator.
	 *
	 * @return array{path: string, samples: int, clicks: array<int, array{x: int, y: int, count: int}>, scroll: array<int, int>} The row.
	 */
	private function finish(array $page): array {
		$clicks = [];
		foreach ($page['cells'] as $key => $count) {
			[$column, $row] = explode(':', (string)$key);
			$clicks[] = ['x' => (int)$column, 'y' => (int)$row, 'count' => (int)$count];
		}

		usort($clicks, static fn (array $a, array $b): int => [$b['count'], $a['y'], $a['x']] <=> [$a['count'], $b['y'], $b['x']]);

		return ['path' => (string)$page['path'], 'samples' => (int)$page['samples'], 'clicks' => $clicks, 'scroll' => array_values($page['scroll'])];
	}

	/**
	 * The event's params as a map.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return array<string, mixed> The params.
	 */
	private function params(array $event): array {
		$params = $event['params'] ?? [];
		if (is_array($params) === false) {
			return [];
		}

		return $params;
	}

	/**
	 * A fraction of the page as a grid cell, or null when it is not one.
	 *
	 * @param mixed $value The posted fraction.
	 *
	 * @return int|null The cell index.
	 */
	private function cell(mixed $value): ?int {
		$fraction = $this->fraction(value: $value);
		if ($fraction === null) {
			return null;
		}

		return min(self::GRID - 1, (int)floor($fraction * self::GRID));
	}

	/**
	 * A number between 0 and 1, or null.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return float|null The fraction.
	 */
	private function fraction(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		$fraction = (float)$value;
		if ($fraction < 0.0 || $fraction > 1.0) {
			return null;
		}

		return $fraction;
	}
}
