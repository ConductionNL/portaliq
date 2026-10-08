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

use OCA\Portaliq\Service\Theme\PortalCustomThemeSets;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

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
	 * The app that ships the token stylesheets, newest id first.
	 *
	 * The theme app was published as `nldesign` and is being renamed to
	 * `thematiq`; its `<id>` has already moved on `development` while released
	 * builds still ship the old one. This is a duck-typed cross-app lookup —
	 * `isInstalled()` on a name nothing answers to returns false, so a single
	 * hardcoded id does not error when it goes stale, it silently renders the
	 * portal UNTHEMED. Intact markup, readable text, no console message, no
	 * failed request: nothing about it looks wrong.
	 *
	 * So accept either id and use whichever is actually installed, rather than
	 * picking a flag day. Drop `nldesign` once no supported build ships it.
	 *
	 * @var string[]
	 */
	private const THEME_APP_IDS = ['thematiq', 'nldesign'];

	/**
	 * The theme app's public bridge, relative to its `css/` directory.
	 *
	 * It maps the `--nldesign-*` layer every set defines onto the
	 * `--utrecht-*`, `--tilburg-*` and `--conduction-*` roles the site paints
	 * from (thematiq#355). Without it, 40 of the 52 sets load and change
	 * nothing on the site.
	 *
	 * @var string
	 */
	public const BRIDGE_STYLESHEET = 'public-bridge';

	/**
	 * The faces the theme app bundles (Fira Sans, Source Sans 3), relative to
	 * its `css/` directory. A set names a family; this file declares it.
	 *
	 * @var string
	 */
	public const FONT_STYLESHEET = 'fonts';

	/**
	 * The logo variants a page may ask for, with their file suffix.
	 *
	 * @var array<string, string>
	 */
	private const LOGO_VARIANTS = ['' => '', 'dark' => '-dark', 'emblem' => '-emblem', 'emblem-grey' => '-emblem-grey'];


	/**
	 * Constructor.
	 *
	 * @param IAppManager                $appManager Tells us whether the theme app is present.
	 * @param PortalCustomThemeSets|null $customSets The theme app's custom sets; null offers none.
	 * @param LoggerInterface|null       $logger     Names a theme that does not resolve; null stays silent.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ?PortalCustomThemeSets $customSets = null,
		private readonly ?LoggerInterface $logger = null,
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
		$sheet = $this->resolveStylesheet(theme: $theme);

		// A theme that is named but does not resolve is reported by name
		// (ADR-086 section 6): the page then renders unthemed, and without this
		// line nothing would say why.
		if ($sheet === null && trim($theme) !== '') {
			$this->logger?->warning('Portaliq: theme does not resolve, the portal renders unthemed', ['theme' => mb_substr($theme, 0, 64)]);
		}

		return $sheet;
	}//end stylesheetFor()


	/**
	 * The stylesheet a theme reference resolves to, or null.
	 *
	 * @param string $theme The portal's theme reference.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	private function resolveStylesheet(string $theme): ?string {
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

		// A CUSTOM SET IS CHECKED AGAIN BEFORE IT IS LINKED (task 4.2): it came
		// from an upload or from another instance, and the theme app's own
		// validator decides whether its file may reach a public page.
		if ($this->refusalFor(theme: $theme) !== null) {
			return null;
		}

		return 'tokens/' . $theme;
	}//end resolveStylesheet()


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
	public function parentStylesheetsFor(string $theme): array {
		$byId = [];
		foreach ($this->catalogue() as $entry) {
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
			$sheet = $this->stylesheetFor(theme: $parent);
			if ($sheet !== null) {
				$sheets[] = $sheet;
			}
		}

		return $sheets;
	}//end parentStylesheetsFor()


	/**
	 * The theme app's own stylesheets the site links beside a set, relative
	 * to its `css/` directory, each null when the installed theme app ships
	 * none: its public bridge and its bundled faces.
	 *
	 * Existence is checked on disk (ThemeAppAsset): a link to a missing app
	 * asset fails on every page load and looks like no theme at all. Whether
	 * to link them is the caller's call: only with a resolved set.
	 *
	 * @return array{bridge: string|null, fonts: string|null}
	 *
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-theme-apps-public-bridge-before-a-resolved-token-set-req-stb-001
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-faces-the-theme-app-bundles-req-stb-002
	 */
	public function shippedStylesheets(): array {
		$root = $this->themeAppPath();
		$asset = new ThemeAppAsset();

		return [
			'bridge' => $asset->stylesheetIfShipped(root: $root, name: self::BRIDGE_STYLESHEET),
			'fonts'  => $asset->stylesheetIfShipped(root: $root, name: self::FONT_STYLESHEET),
		];
	}//end shippedStylesheets()


	/**
	 * The theme app path of a set's logo, relative to that app, or null.
	 *
	 * WHY A CALLER NEEDS THIS AT ALL: token sets declare
	 * `--nldesign-logo-url` as a path relative to the token file, which is
	 * correct there and wrong once the token is consumed by a rule in a
	 * DIFFERENT app — a browser resolves a relative `url()` inside a custom
	 * property against the stylesheet doing the consuming. The portal's header
	 * therefore requested the logo from Portaliq's own directory and rendered
	 * nothing, with every token holding the right value.
	 *
	 * Existence is checked here rather than assumed, and a missing file yields
	 * null so the page renders with NO logo instead of a broken image or
	 * another brand's mark — the same posture `stylesheetFor()` takes.
	 *
	 * A variant (site-chrome-follows-the-design) is `dark`, the light logo
	 * for a dark band such as a school portal's footer (`<theme>-dark.svg`,
	 * named after the dark scheme it was drawn for), or `emblem`, the mark
	 * without the name for a faint watermark (`<theme>-emblem.svg`), or
	 * `emblem-grey`, that mark in grey for a set whose watermark carries no
	 * tint (`<theme>-emblem-grey.svg`, example-site-zuiddrecht).
	 *
	 * @param string $theme   The portal's theme reference, e.g. 'opencatalogi'.
	 * @param string $variant '' for the logo, else `dark`, `emblem` or `emblem-grey`.
	 *
	 * @return string|null The path relative to the theme app, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function logoFileFor(string $theme, string $variant=''): ?string {
		if ($this->stylesheetFor(theme: $theme) === null) {
			return null;
		}

		$root = $this->themeAppPath();
		if ($root === null) {
			return null;
		}

		// An unknown variant names a path no theme app ships, so it ends in
		// the missing-file answer below.
		$relative = 'img/logos/' . $theme . (self::LOGO_VARIANTS[$variant] ?? '/-') . '.svg';
		if (is_file($root . '/' . $relative) === false) {
			return null;
		}

		return $relative;
	}//end logoFileFor()


	/**
	 * Whether the theme app's catalogue offers this set.
	 *
	 * Reads `token-sets.json` from disk rather than calling the app's
	 * `/api/token-sets` endpoint. That endpoint is `#[NoAdminRequired]` and
	 * DELIBERATELY not `#[PublicPage]` — its own docblock records that exposing
	 * admin-uploaded custom sets to anonymous traffic would be an information-
	 * disclosure surface with no consumer need. The site renderer serves
	 * anonymous visitors, so it must not become that consumer; the admin UI,
	 * which is authenticated, is the endpoint's intended caller and uses it for
	 * the theme picker.
	 *
	 * An unreadable or malformed catalogue answers NO. A portal then renders
	 * unstyled, which is the same fail-closed posture as an unknown theme.
	 *
	 * @param string $theme The theme reference.
	 *
	 * @return bool Whether the catalogue lists it.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	private function catalogueHas(string $theme): bool {
		foreach ($this->catalogue() as $entry) {
			if ($entry['id'] === $theme) {
				return true;
			}
		}

		return false;
	}//end catalogueHas()

	/**
	 * Every set the theme app's catalogue offers, read from its
	 * `token-sets.json` on disk (see `catalogueHas()` for why not the
	 * endpoint). An unreadable or malformed catalogue answers an empty list, so
	 * a picker shows nothing rather than a default.
	 *
	 * @return array<int, array<string, mixed>> Each entry has at least a string `id`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function catalogue(): array {
		$root = $this->themeAppPath();
		if ($root === null) {
			return [];
		}

		$path = $root . '/token-sets.json';
		if (is_file($path) === false) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$sets = [];
		$ids = [];
		foreach (array_values($decoded) as $entry) {
			if (is_array($entry) === true && is_string($entry['id'] ?? null) === true) {
				$sets[] = $entry;
				$ids[$entry['id']] = true;
			}
		}

		// The sets an administrator made or received in the theme app
		// (task 4.1): an upload, or a theme shared through OpenRegister that
		// the theme app imported as a custom set.
		foreach (($this->customSets?->all() ?? []) as $entry) {
			if (isset($ids[$entry['id']]) === false) {
				$sets[] = $entry;
			}
		}

		return $sets;
	}//end catalogue()

	/**
	 * Why a custom set may not be linked, in the theme app's words, or null
	 * when it may (or when the set is not a custom one).
	 *
	 * @param string $theme The set id.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function refusalFor(string $theme): ?string {
		if ($this->customSets === null || $this->customSets->isCustomId(id: $theme) === false) {
			return null;
		}

		$root = $this->themeAppPath();
		$file = ($root ?? '') . '/css/tokens/' . $theme . '.css';
		if ($root === null || is_file($file) === false) {
			return null;
		}

		return $this->customSets->refusal(id: $theme, css: (string)file_get_contents($file));
	}//end refusalFor()

	/**
	 * The theme app's public stylesheet of administrator-uploaded font faces,
	 * as a route name, or null when the installed theme app has none.
	 *
	 * The route is public on purpose in the theme app (a CSS font load carries
	 * no session), so an anonymous portal visitor can load it. Asked of the
	 * installed build, because a build without font uploads has no such route
	 * and linking one would name a URL nothing answers.
	 *
	 * @return string|null E.g. `thematiq.font.css`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function fontStylesheetRoute(): ?string {
		$root = $this->themeAppPath();
		$id = $this->themeAppId();
		if ($root === null || $id === null || is_file($root . '/lib/Controller/FontController.php') === false) {
			return null;
		}

		return $id . '.font.css';
	}//end fontStylesheetRoute()

	/**
	 * The token values a resolvable set declares, for the contrast check.
	 *
	 * Read from the set's token file, the only place that says what colour
	 * anything is. Declarations are matched on the custom-property syntax: a
	 * generated token file has one per line. A `var()` alias is resolved one
	 * hop against the same file, because a set's roles alias its own palette;
	 * a value still unresolved stays as it is and the contrast service reports
	 * it as unevaluated rather than guessing.
	 *
	 * @param string $theme The set id.
	 *
	 * @return array<string, string> Token name to value; empty when the set does not resolve.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function tokenValuesFor(string $theme): array {
		$sheet = $this->stylesheetFor(theme: $theme);
		$root = $this->themeAppPath();
		if ($sheet === null || $root === null) {
			return [];
		}

		preg_match_all(
			'/(--[a-z0-9-]+)\s*:\s*([^;}]+)[;}]/i',
			(string)file_get_contents($root . '/css/' . $sheet . '.css'),
			$matches,
			PREG_SET_ORDER
		);

		$values = [];
		foreach ($matches as $match) {
			$values[trim($match[1])] = trim($match[2]);
		}

		foreach ($values as $name => $value) {
			if (preg_match('/^var\(\s*(--[a-z0-9-]+)/i', $value, $alias) === 1) {
				$values[$name] = ($values[$alias[1]] ?? $value);
			}
		}

		return $values;
	}//end tokenValuesFor()


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
	 * Which theme-app id this instance actually answers to.
	 *
	 * @return string|null The installed id, or null when no candidate is.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function themeAppId(): ?string {
		foreach (self::THEME_APP_IDS as $id) {
			try {
				if ($this->appManager->isInstalled($id) === true) {
					return $id;
				}
			} catch (\Throwable) {
				// Ask the next candidate: one unknown id must not decide the
				// answer for the others.
				continue;
			}
		}

		return null;
	}//end themeAppId()


	/**
	 * The theme app's directory, or null when it is not installed.
	 *
	 * @return string|null The path.
	 */
	private function themeAppPath(): ?string {
		try {
			$id = $this->themeAppId();
			if ($id === null) {
				return null;
			}

			return $this->appManager->getAppPath($id);
		} catch (\Throwable) {
			// A missing theme app is a normal deployment, not a fault: a
			// Portaliq that renders unthemed is still a working Portaliq.
			return null;
		}
	}//end themeAppPath()


}//end class
