<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Resolves a portal's theme reference to a real token stylesheet (ADR-086 §6).
 *
 * @category  Service
 * @package   OCA\Portaliq\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Theme;

use OCA\Portaliq\Service\PortalThemeResolver;

/**
 * Follows `extends` through the theme catalogue.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-theme-that-extends-another-must-load-its-parent-first-req-ptb-003
 */
class PortalThemeParents {
	/**
	 * Constructor.
	 *
	 * @param PortalThemeResolver $resolver Reads the catalogue and resolves a set to its stylesheet.
	 */
	public function __construct(
		private readonly PortalThemeResolver $resolver,
	) {
	}//end __construct()

	/**
	 * The sets a theme extends, parent first, as stylesheet paths that resolve.
	 *
	 * Follows `extends` in the catalogue for at most four hops and stops at a
	 * set already in the chain, so a cycle links each set once. The theme
	 * itself is not in the list. A parent that does not resolve is skipped.
	 *
	 * @param string $theme The portal's theme reference.
	 *
	 * @return array<int, string> Stylesheet paths relative to the theme app's `css/`, parent first.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-theme-that-extends-another-must-load-its-parent-first-req-ptb-003
	 */
	public function stylesheetsFor(string $theme): array {
		$byId = [];
		foreach ($this->resolver->catalogue() as $entry) {
			$byId[(string)$entry['id']] = $entry;
		}

		$seen = [$theme => true];
		$parents = [];
		$current = $theme;
		for ($hop = 0; $hop < 4; $hop++) {
			$parent = (string)(($byId[$current] ?? [])['extends'] ?? '');
			if ($parent === '' || isset($seen[$parent]) === true) {
				break;
			}

			$seen[$parent] = true;
			array_unshift($parents, $parent);
			$current = $parent;
		}

		$sheets = [];
		foreach ($parents as $parent) {
			$sheet = $this->resolver->stylesheetFor(theme: $parent);
			if ($sheet !== null) {
				$sheets[] = $sheet;
			}
		}

		return $sheets;
	}//end stylesheetsFor()
}//end class
