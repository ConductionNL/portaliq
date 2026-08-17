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
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
 */
class PortalThemeResolver {

	/**
	 * The app that ships the token stylesheets.
	 */
	public const THEME_APP = 'nldesign';

	/**
	 * How many `extends` hops are followed before giving up.
	 *
	 * The bound is also the answer to a cycle: a chain this long is a
	 * catalogue mistake, not a theme.
	 */
	private const MAX_EXTENDS_DEPTH = 4;


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

		// RESOLVED AGAINST THE THEME APP'S OWN CATALOGUE, not just the
		// filesystem. `token-sets.json` is what that app treats as the list of
		// sets it offers; a `.css` file sitting beside it is not necessarily a
		// set on offer — a generated dark variant, a work-in-progress or a
		// leftover would all pass a bare `is_file()` and none of them is a
		// theme a portal may adopt.
		//
		// Asking the catalogue also means a portal and the theme app agree on
		// what exists, which is the whole point of one app owning theming.
		if ($this->catalogueHas(theme: $theme) === false) {
			return null;
		}

		// AND the file must EXIST. Both checks, because they fail differently:
		// a catalogued set with no file means a broken install, and emitting a
		// link that 404s is indistinguishable on screen from having no theme —
		// it moves the failure from somewhere we can check to somewhere only
		// the browser sees.
		if (is_file($root . '/css/tokens/' . $theme . '.css') === false) {
			return null;
		}

		return 'tokens/' . $theme;
	}//end stylesheetFor()


	/**
	 * The chain of stylesheets for a theme: every ancestor first, then the
	 * theme itself.
	 *
	 * A SET MAY EXTEND ANOTHER, and several do. `frankendesk` says so in its own
	 * header — "same identity values as lasuite, distinguished only by the
	 * logo" — which described a COPY: 21 declarations duplicated into 22, kept
	 * in step by hand and drifting the moment either side is edited. Declaring
	 * `extends` in the catalogue turns the copy into a delta: the parent loads
	 * first, the child second, and the child contains only what it changes.
	 *
	 * Depth-bounded and cycle-guarded, because the catalogue is data: two sets
	 * naming each other must cost a bounded number of lookups, not the request.
	 *
	 * @param string $theme The portal's theme reference.
	 *
	 * @return string[] Stylesheet paths relative to the theme app's `css/`,
	 *                  ancestors first. Empty when the theme does not resolve.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function stylesheetChainFor(string $theme): array {
		$own = $this->stylesheetFor(theme: $theme);
		if ($own === null) {
			return [];
		}

		$chain = [$own];
		$seen = [$theme => true];
		$current = $theme;

		for ($hop = 0; $hop < self::MAX_EXTENDS_DEPTH; $hop++) {
			$parent = $this->parentOf(theme: $current);
			if ($parent === null || isset($seen[$parent]) === true) {
				break;
			}

			$parentSheet = $this->stylesheetFor(theme: $parent);
			if ($parentSheet === null) {
				// A named parent that does not resolve is NOT fatal: the child
				// still styles the page, just without the values it expected to
				// inherit. Failing the whole theme over a broken reference
				// would take a working portal down for a catalogue typo.
				break;
			}

			array_unshift($chain, $parentSheet);
			$seen[$parent] = true;
			$current = $parent;
		}

		return $chain;
	}//end stylesheetChainFor()


	/**
	 * The set a theme extends, or null.
	 *
	 * @param string $theme The theme reference.
	 *
	 * @return string|null The parent set id, or null.
	 */
	private function parentOf(string $theme): ?string {
		$entry = $this->catalogueEntry(theme: $theme);
		if ($entry === null) {
			return null;
		}

		$parent = ($entry['extends'] ?? null);
		if (is_string($parent) === false || $parent === '') {
			return null;
		}

		// A parent name reaches the filesystem through `stylesheetFor()`, so it
		// gets the same validation as a portal-supplied theme. The catalogue is
		// shipped rather than user input today, but it is admin-writable and the
		// check costs nothing.
		if ($this->isSafeThemeName(theme: $parent) === false) {
			return null;
		}

		return $parent;
	}//end parentOf()


	/**
	 * The catalogue entry for a set, or null when it lists no such set.
	 *
	 * Extracted because `catalogueHas()` and `parentOf()` ask two questions of
	 * the same file and had grown two copies of the read — including two copies
	 * of the list-or-map tolerance below, which is exactly the kind of thing
	 * that gets fixed in one place and not the other.
	 *
	 * Reads `token-sets.json` from disk rather than calling the theme app's
	 * `/api/token-sets` endpoint. That endpoint is `#[NoAdminRequired]` and
	 * DELIBERATELY not `#[PublicPage]` — its own docblock records that exposing
	 * admin-uploaded custom sets to anonymous traffic would be an information-
	 * disclosure surface with no consumer need. The site renderer serves
	 * anonymous visitors, so it must not become that consumer.
	 *
	 * An unreadable or malformed catalogue answers null, so every caller
	 * fails closed.
	 *
	 * @param string $theme The theme reference.
	 *
	 * @return array|null The entry, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	private function catalogueEntry(string $theme): ?array {
		$root = $this->themeAppPath();
		if ($root === null) {
			return null;
		}

		$path = $root . '/token-sets.json';
		if (is_file($path) === false) {
			return null;
		}

		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false) {
			return null;
		}

		// The file ships as a LIST of set objects; tolerate a keyed map too,
		// because which of the two it is has changed upstream before.
		$entries = $decoded;
		if (array_is_list($decoded) === false) {
			$entries = array_values($decoded);
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === true && ($entry['id'] ?? null) === $theme) {
				return $entry;
			}
		}

		return null;
	}//end catalogueEntry()


	/**
	 * Whether the theme app's catalogue offers this set.
	 *
	 * An unreadable or malformed catalogue answers NO. A portal then renders
	 * unstyled, which is the same fail-closed posture as an unknown theme —
	 * see `catalogueEntry()` for why the file is read rather than the API.
	 *
	 * @param string $theme The theme reference.
	 *
	 * @return bool Whether the catalogue lists it.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	private function catalogueHas(string $theme): bool {
		return $this->catalogueEntry(theme: $theme) !== null;
	}//end catalogueHas()


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
		// DELIBERATELY RETURNS NULL, ALWAYS — and the method stays because
		// `templates/site.php` still asks the question.
		//
		// This app used to ship its own `css/themes/<theme>.css`: 600
		// `--utrecht-*` and 254 `--tilburg-*` tokens, vendored from the
		// reference implementation. That made a SECOND source of truth for a
		// theme the `nldesign` app already owns — two derivations of one
		// upstream file (`tilburg-woo-ui/.../_tokens-vng.scss`), maintained
		// separately and already drifted apart.
		//
		// Worse, the halves were disjoint: measured, ZERO overlap between the
		// tokens nldesign's chain defined and the ones this app's copy did. The
		// portal was styled entirely from here, and nldesign's
		// `utrecht-bridge.css` — which maps every Nextcloud-facing
		// `--nldesign-component-*` from an `--utrecht-*` — had none of its 88
		// inputs supplied, so the Nextcloud UI silently ran on Rijkshuisstijl
		// fallbacks.
		//
		// The token set now lives in `nldesign/css/tokens/<theme>.css`, which
		// `stylesheetFor()` already resolves, so one change styles both ends.
		// PORTALIQ TRUSTS NLDESIGN FOR STYLING and ships no tokens of its own
		// (ADR-086 §6 said as much; this makes it true).
		unset($theme);

		return null;
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
