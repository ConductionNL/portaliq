<?php

/**
 * The five regions of a portal page, and which region a widget belongs to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use stdClass;

/**
 * Groups widgets by region for the content contract.
 *
 * The list of regions is closed. A widget's `slot` names its region, and
 * `body` or an empty slot means `main`, so no stored page needs a migration.
 * A slot that names no region is reported by name, never dropped silently.
 *
 * Resolution (page first, then portal, then the built-in default) is the
 * renderer's: `src/site/lib/regions.js`. This class serves both inputs of that
 * rule with their keys intact, because a present key with an empty list is
 * the only way to say "this region is empty on purpose".
 */
class PortalRegionResolver {

	/**
	 * The regions a page has, in render order. Mirrors `REGIONS` in
	 * `src/site/lib/regions.js`.
	 */
	public const REGIONS = ['header', 'hero', 'main', 'aside', 'footer'];

	/**
	 * The region a slot names, or null when it names none.
	 *
	 * @param mixed $slot The widget's stored slot.
	 *
	 * @return string|null The region.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
	 */
	public function regionFor(mixed $slot): ?string {
		if ($slot === null || $slot === '' || $slot === 'body') {
			return 'main';
		}

		if (is_string($slot) === true && in_array($slot, self::REGIONS, true) === true) {
			return $slot;
		}

		return null;
	}//end regionFor()

	/**
	 * Group shaped widgets by region, in the order given.
	 *
	 * Only regions that hold a widget get a key: an absent key means "the
	 * page does not fill this region", which lets it inherit.
	 *
	 * @param array<int, array<string, mixed>> $widgets The shaped widgets.
	 *
	 * @return array{regions: array<string, list<array<string, mixed>>>, unknownRegions: list<string>}
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
	 */
	public function group(array $widgets): array {
		$regions = [];
		$unknown = [];
		foreach ($widgets as $widget) {
			$slot   = ($widget['slot'] ?? null);
			$region = $this->regionFor(slot: $slot);
			if ($region === null) {
				$unknown[] = $this->slotName(slot: $slot);
				continue;
			}

			$regions[$region][] = $widget;
		}

		return [
			'regions'        => $this->ordered(regions: $regions),
			'unknownRegions' => array_values(array_unique($unknown)),
		];
	}//end group()

	/**
	 * A slot as text, for the report of unknown regions.
	 *
	 * @param mixed $slot The stored slot.
	 *
	 * @return string The slot, or '' when it is not text.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
	 */
	private function slotName(mixed $slot): string {
		if (is_scalar($slot) === false) {
			return '';
		}

		return (string)$slot;
	}//end slotName()

	/**
	 * A region map for a JSON response: an empty map stays an object.
	 *
	 * @param array<string, mixed> $regions Region name to widgets.
	 *
	 * @return array<string, mixed>|stdClass The map, or `{}` when empty.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
	 */
	public function forJson(array $regions): array|stdClass {
		if ($regions === []) {
			return new stdClass();
		}

		return $regions;
	}//end forJson()

	/**
	 * The regions a page empties on purpose: known names only, each once.
	 *
	 * @param mixed $cleared The stored `clearedRegions`.
	 *
	 * @return list<string> Region names.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
	 */
	public function cleared(mixed $cleared): array {
		if (is_array($cleared) === false) {
			return [];
		}

		return array_values(array_intersect(self::REGIONS, $cleared));
	}//end cleared()

	/**
	 * Keep only known regions, in render order, preserving a present key even
	 * when its list is empty.
	 *
	 * @param array<string, mixed> $regions Region name to widget list.
	 *
	 * @return array<string, list<array<string, mixed>>> The known regions.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
	 */
	public function ordered(array $regions): array {
		$known = [];
		foreach (self::REGIONS as $region) {
			if (array_key_exists($region, $regions) === true) {
				$known[$region] = array_values(array_filter((array)$regions[$region], 'is_array'));
			}
		}

		return $known;
	}//end ordered()
}//end class
