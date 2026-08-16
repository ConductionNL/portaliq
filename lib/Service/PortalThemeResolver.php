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

namespace OCA\Portaliq\Service;

use OCP\App\IAppManager;

/**
 * Maps `portal.theme` onto the themiq (nldesign) token stylesheet that
 * actually defines the custom properties the portal renders with.
 *
 * WHY THIS EXISTS AT ALL: BEFORE IT, THEMING RENDERED NOTHING.
 * ------------------------------------------------------------
 * The renderer put a `<theme>-theme` class on its root and stopped there. No
 * stylesheet ever defined tokens for that class, so two portals with different
 * `theme` values computed IDENTICAL colours — and a test asserting the class
 * STRING passed, which is how it survived. Assert the computed value.
 *
 * WHAT A THEME IS: a file of `--nldesign-color-*` custom properties on
 * `:root`, shipped by the `nldesign` app (44 of them: `vng`, `venray`,
 * `tilburg`, `rijkshuisstijl`, …). Portaliq defines NO tokens of its own; it
 * only decides which single file a portal loads.
 *
 * WHY AN UNRESOLVABLE THEME RESOLVES TO NOTHING, NOT TO A DEFAULT
 * ---------------------------------------------------------------
 * Falling back to another municipality's tokens would render a Venray portal
 * in Tilburg's brand — a page that looks completely fine and is wrong in the
 * one way nobody screenshots. An unstyled page is visibly broken, gets
 * reported, and gets fixed. So a missing theme yields `null` and the caller
 * renders unthemed; ADR-086 §6 requires the failure to name the theme rather
 * than disguise it.
 */
class PortalThemeResolver {

	/**
	 * The app that ships the token stylesheets.
	 */
	public const THEME_APP = 'nldesign';


	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Tells us whether the theme app is present.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()


	/**
	 * The token stylesheet for a theme reference, relative to the theme app's
	 * `css/` directory, or null when it does not resolve.
	 *
	 * @param string $theme The portal's theme reference, e.g. 'vng'.
	 *
	 * @return string|null The stylesheet path, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function stylesheetFor(string $theme): ?string {
		if ($this->isSafeThemeName(theme: $theme) === false) {
			return null;
		}

		$root = $this->themeAppPath();
		if ($root === null) {
			return null;
		}

		// The file must EXIST. Emitting a link to a stylesheet that 404s is
		// indistinguishable, on screen, from having no theme — and it moves
		// the failure from a place we can check to a place only the browser
		// sees.
		if (is_file($root . '/css/tokens/' . $theme . '.css') === false) {
			return null;
		}

		return 'tokens/' . $theme;
	}//end stylesheetFor()


	/**
	 * The NL Design System token stylesheet this app ships for a theme, or
	 * null.
	 *
	 * WHY THIS EXISTS ALONGSIDE `stylesheetFor()`. The theme app's
	 * `css/tokens/*.css` files are hand-converted and define `--nldesign-*`
	 * names. The Utrecht/NLDS component CSS the public site renders with reads
	 * `--utrecht-*` — and the hand-converted VNG file contains **zero** of
	 * those, so every component fell back to its own default no matter which
	 * theme was selected. That is why a themed portal still looked like
	 * Nextcloud.
	 *
	 * These files are the real generated token sets (vng: 605 `--utrecht-*`,
	 * venray: 532), scoped `.vng-theme` / `.venray-theme` — the class
	 * `App.vue` already puts on the site root.
	 *
	 * Served as a LINKED stylesheet rather than bundled: the two themes total
	 * 215KB, which took the site bundle from 203KB to 696KB and blew the
	 * 400KB public first-load budget e2e S18 enforces — for themes a given
	 * visitor will never both need.
	 *
	 * @param string $theme The portal's theme reference, e.g. 'vng'.
	 *
	 * @return string|null The stylesheet path relative to this app's `css/`,
	 *                     or null when this app ships no tokens for it.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function nldsStylesheetFor(string $theme): ?string {
		if ($this->isSafeThemeName(theme: $theme) === false) {
			return null;
		}

		if (is_file(__DIR__ . '/../../css/themes/' . $theme . '.css') === false) {
			return null;
		}

		return 'themes/' . $theme;
	}//end nldsStylesheetFor()


	/**
	 * Whether a theme reference is safe to put in a filesystem path.
	 *
	 * The value comes from an OpenRegister object an editor controls, and it
	 * is about to be concatenated into a path. `../../` in a theme field must
	 * not be able to name a file outside the token directory, so this is an
	 * ALLOW-list of the shape real theme slugs have, not a deny-list of the
	 * traversals thought of today.
	 *
	 * @param string $theme The candidate.
	 *
	 * @return bool True when it is a plain lowercase slug.
	 */
	private function isSafeThemeName(string $theme): bool {
		if ($theme === '') {
			return false;
		}

		return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $theme) === 1;
	}//end isSafeThemeName()


	/**
	 * The theme app's directory, or null when it is not installed.
	 *
	 * @return string|null The path.
	 */
	private function themeAppPath(): ?string {
		try {
			if ($this->appManager->isInstalled(self::THEME_APP) === false) {
				return null;
			}

			return $this->appManager->getAppPath(self::THEME_APP);
		} catch (\Throwable) {
			// A missing theme app is a normal deployment, not a fault: a
			// Portaliq that renders unthemed is still a working Portaliq.
			return null;
		}
	}//end themeAppPath()


}//end class
