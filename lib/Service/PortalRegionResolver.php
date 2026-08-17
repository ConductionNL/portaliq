<?php

/**
 * Portaliq Portal Region Resolver
 *
 * Which widgets belong to which region of a page, and whose widgets win.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Resolves a page's regions from the portal's defaults and the page's own.
 *
 * PURE. It takes two maps and returns one; it reads no clock, no request and
 * no store. The properties this has to have — that an explicitly empty region
 * differs from an absent one, and that resolution is the same every time — are
 * only testable exhaustively because there is nothing else in the way.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
class PortalRegionResolver {

	/**
	 * The regions a page has, in render order.
	 *
	 * CLOSED, and that is the point. An author can place a widget anywhere in
	 * this list and nowhere else; a typo'd region is reported rather than
	 * silently becoming a sixth region nothing renders.
	 */
	public const REGIONS = ['header', 'hero', 'main', 'aside', 'footer'];

	/**
	 * The legacy slot value every seeded widget carries.
	 *
	 * Nine widgets in the shipped seed say `"slot": "body"` and nothing has
	 * ever read it (task 0.3). It means `main`, and mapping it here is what
	 * lets the region model arrive without a data migration — a migration that
	 * changes every portal's appearance is not a migration.
	 */
	private const LEGACY_MAIN = 'body';


	/**
	 * Group a flat widget list into regions by its `slot`.
	 *
	 * A LEGACY LIST CAN NEVER PRODUCE AN EXPLICITLY EMPTY REGION. A region with
	 * no widgets in a flat list means the page said nothing about it, not that
	 * the page wants it blank — so absent regions are simply not keyed, and
	 * inheritance still applies. That distinction is the whole of task 1.3, and
	 * getting it backwards would blank the header on every existing page.
	 *
	 * @param array<int, array<string, mixed>> $widgets The page's widgets.
	 *
	 * @return array{regions: array<string, array<int, array<string, mixed>>>, unknown: array<int, string>}
	 *
	 * @spec openspec/changes/portal-page-composition/tasks.md
	 */
	public function regionsFromWidgets(array $widgets): array {
		$regions = [];
		$unknown = [];

		foreach ($widgets as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			$slot = trim((string)($widget['slot'] ?? ''));
			if ($slot === '' || $slot === self::LEGACY_MAIN) {
				$slot = 'main';
			}

			if (in_array($slot, self::REGIONS, true) === false) {
				// REPORTED, NOT DROPPED. A widget assigned to a region that
				// does not exist is an authoring mistake, and the author needs
				// to see the name they typed — a widget that silently vanishes
				// is debugged by guessing.
				if (in_array($slot, $unknown, true) === false) {
					$unknown[] = $slot;
				}

				continue;
			}

			$regions[$slot][] = $widget;
		}

		foreach ($regions as $name => $list) {
			$regions[$name] = $this->ordered(widgets: $list);
		}

		return ['regions' => $regions, 'unknown' => $unknown];
	}//end regionsFromWidgets()


	/**
	 * Resolve a page's regions against the portal's defaults.
	 *
	 * THREE STATES PER REGION, and the third is the one a naive resolver gets
	 * wrong:
	 *
	 *   inherited  — the page does not mention the region; the portal's wins.
	 *   overridden — the page lists widgets; the page's wins.
	 *   emptied    — the page lists the region with NO widgets; nothing renders.
	 *
	 * A resolver that treats an empty list as "unset" passes the first two and
	 * fails the third, which is why the test covers all three per region. The
	 * emptied state is what lets a single landing page drop the portal's hero
	 * without deleting it for every other page.
	 *
	 * @param array<string, mixed> $pageRegions   The page's regions, keys meaningful.
	 * @param array<string, mixed> $portalRegions The portal's default regions.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Region to widgets, every region keyed.
	 *
	 * @spec openspec/changes/portal-page-composition/tasks.md
	 */
	public function resolve(array $pageRegions, array $portalRegions): array {
		$resolved = [];

		foreach (self::REGIONS as $region) {
			// `array_key_exists`, NOT `isset` and NOT `??`. Both of those treat
			// a present-but-empty value as absent, which collapses "emptied"
			// into "inherited" — the exact defect this method exists to avoid.
			if (array_key_exists($region, $pageRegions) === true) {
				$resolved[$region] = $this->ordered(widgets: (array)$pageRegions[$region]);
				continue;
			}

			$resolved[$region] = $this->ordered(widgets: (array)($portalRegions[$region] ?? []));
		}

		return $resolved;
	}//end resolve()


	/**
	 * Widgets in render order.
	 *
	 * Row before column, the same order the grid already lays out in. Sorting
	 * here rather than trusting the stored order means a page edited by hand,
	 * or one whose widgets came back from storage in a different order, still
	 * renders the same way.
	 *
	 * @param array<int, mixed> $widgets The widgets.
	 *
	 * @return array<int, array<string, mixed>> The ordered widgets.
	 */
	private function ordered(array $widgets): array {
		$list = [];
		foreach ($widgets as $widget) {
			if (is_array($widget) === true) {
				$list[] = $widget;
			}
		}

		usort(
			$list,
			static function (array $first, array $second): int {
				$firstAt = [(int)($first['gridY'] ?? 0), (int)($first['gridX'] ?? 0)];
				$secondAt = [(int)($second['gridY'] ?? 0), (int)($second['gridX'] ?? 0)];
				return ($firstAt <=> $secondAt);
			}
		);

		return $list;
	}//end ordered()


}//end class
