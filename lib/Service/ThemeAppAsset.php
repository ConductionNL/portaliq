<?php

/**
 * Portaliq Theme App Asset
 *
 * Answers whether the installed theme app ships a stylesheet, by name, under
 * its `css/` directory. Checked on disk because Nextcloud answers a missing
 * app asset with 401, and a link that fails looks like no theme at all.
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
 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-theme-apps-public-bridge-before-a-resolved-token-set-req-stb-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * A theme app stylesheet that exists on disk.
 *
 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-theme-apps-public-bridge-before-a-resolved-token-set-req-stb-001
 */
class ThemeAppAsset {
	/**
	 * The stylesheet's name when `<root>/css/<name>.css` exists, else null.
	 *
	 * @param string|null $root The theme app's directory, or null when it is not installed.
	 * @param string      $name The stylesheet name, without `.css`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-theme-apps-public-bridge-before-a-resolved-token-set-req-stb-001
	 */
	public function stylesheetIfShipped(?string $root, string $name): ?string {
		if ($root === null || is_file($root . '/css/' . $name . '.css') === false) {
			return null;
		}

		return $name;
	}//end stylesheetIfShipped()
}//end class
