<?php

/**
 * Portaliq Site Shell Theme (portal-theme-blocks-and-contributed-pages)
 *
 * The theme of the serving portal, as the site shell needs it before the API answers.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The theme of the portal a site request names: stylesheet, parents, token
 * overrides, logo and app sheets. Every method resolves the portal again, as
 * the shell always did.
 *
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */
class SiteShellTheme {
	/**
	 * Constructor.
	 *
	 * @param IRequest            $request        The request.
	 * @param PortalResolver      $portalResolver Resolves the serving portal.
	 * @param PortalThemeResolver $themeResolver Maps the portal's theme to a token stylesheet.
	 * @param IURLGenerator       $urlGenerator   Builds the logo address.
	 * @param string              $slug           The portal slug the request names, or ''.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly PortalResolver $portalResolver,
		private readonly PortalThemeResolver $themeResolver,
		private readonly IURLGenerator $urlGenerator,
		private readonly string $slug,
	) {
	}//end __construct()

	/**
	 * The token stylesheet the serving portal's theme resolves to, or ''.
	 *
	 * Returns the empty string for every failure — unknown host, no theme,
	 * theme app absent, theme file missing. That is deliberate and it is the
	 * same answer in each case: the page renders UNSTYLED rather than in
	 * whichever brand happened to be first. A portal quietly wearing another
	 * municipality's colours looks correct in every screenshot; an unstyled
	 * one gets reported within the hour.
	 *
	 * @return string The stylesheet path relative to the theme app's css/, or ''.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function stylesheet(): string {
		try {
			$portal = $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->slug
			);
		} catch (\Throwable) {
			return '';
		}

		if ($portal === null) {
			return '';
		}

		return (string)$this->themeResolver->stylesheetFor(
			theme: (string)($portal['theme'] ?? '')
		);
	}//end stylesheet()

	/**
	 * The stylesheets of the sets the serving portal's theme extends, parent first.
	 *
	 * @return array<int, string> Paths relative to the theme app's `css/`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-theme-that-extends-another-must-load-its-parent-first-req-ptb-003
	 */
	public function parents(): array {
		$portal = $this->servingPortalOrNull();
		if ($portal === null || $this->stylesheet() === '') {
			return [];
		}

		return $this->themeResolver->parentStylesheetsFor(theme: (string)($portal['theme'] ?? ''));
	}//end parents()

	/**
	 * The serving portal's own token overrides as a `:root` block, or ''.
	 *
	 * @return string The CSS.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-portal-must-be-able-to-override-its-themes-tokens-safely-req-ptb-002
	 */
	public function tokenCss(): string {
		$portal = $this->servingPortalOrNull();
		if ($portal === null || $this->stylesheet() === '') {
			return '';
		}

		return (new PortalTokenCss())->css(tokens: ($portal['tokens'] ?? null));
	}//end tokenCss()

	/**
	 * The portal this request is served from, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function servingPortalOrNull(): ?array {
		try {
			return $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->slug
			);
		} catch (\Throwable) {
			return null;
		}
	}//end servingPortalOrNull()

	/**
	 * The theme app's own stylesheets for the serving portal: its public
	 * bridge and its bundled faces, each '' when not shipped.
	 *
	 * Only with a resolved set: the bridge carries fallbacks, so linking it on
	 * an unthemed portal would quietly restyle a page that must render
	 * unstyled, and an unthemed page names no bundled family.
	 *
	 * @return array{bridge: string, fonts: string, logoInverse: string, emblem: string, emblemGrey: string} The stylesheets
	 *         (relative to the theme app's `css/`) and the logo variants (absolute).
	 *
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-theme-apps-public-bridge-before-a-resolved-token-set-req-stb-001
	 * @spec openspec/changes/site-links-the-theme-bridge/specs/portaliq-cms/spec.md#requirement-the-site-must-link-the-faces-the-theme-app-bundles-req-stb-002
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
	 */
	public function appSheets(): array {
		$none = ['bridge' => '', 'fonts' => '', 'logoInverse' => '', 'emblem' => '', 'emblemGrey' => ''];
		if ($this->stylesheet() === '') {
			return $none;
		}

		try {
			$shipped = $this->themeResolver->shippedStylesheets();
			return [
				'bridge' => (string)($shipped['bridge'] ?? ''),
				'fonts'  => (string)($shipped['fonts'] ?? ''),
				// The set's light logo for the dark footer and its emblem for
				// a watermark, absolute, or '' (site-chrome-follows-the-design).
				'logoInverse' => $this->logoUrl(variant: 'dark'),
				'emblem'      => $this->logoUrl(variant: 'emblem'),
				// The emblem in grey, for a set whose watermark carries no
				// tint, or '' (example-site-zuiddrecht).
				'emblemGrey'  => $this->logoUrl(variant: 'emblem-grey'),
			];
		} catch (\Throwable) {
			return $none;
		}
	}//end appSheets()

	/**
	 * An ABSOLUTE URL for the serving portal's theme logo, or ''.
	 *
	 * Token sets declare `--nldesign-logo-url` as a path relative to the token
	 * file. That is correct there and wrong by the time it is used: the rule
	 * consuming the token lives in THIS app's bundled NL Design System CSS,
	 * and a browser resolves a relative `url()` inside a custom property
	 * against the stylesheet doing the consuming.
	 *
	 * Measured on the demo rig: the header requested
	 * `/custom_apps/portaliq/img/logos/opencatalogi.svg` — this app's
	 * directory — and the portal rendered with no logo, while every token
	 * involved held exactly the right value. Nothing about the tokens looked
	 * wrong, because nothing about them was.
	 *
	 * So the resolution happens here, where the theme app's real path is
	 * known, and the template emits the result after the token stylesheets.
	 *
	 * With a `$variant` it is that variant of the set's logo
	 * (site-chrome-follows-the-design): `dark`, the light logo for the dark
	 * footer band, or `emblem`, the mark for a watermark; '' when the set
	 * ships none.
	 *
	 * @param string $variant '' for the logo, else `dark`, `emblem` or `emblem-grey`.
	 *
	 * @return string An absolute URL, or '' when there is no logo to serve.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
	 */
	public function logoUrl(string $variant=''): string {
		$stylesheet = $this->stylesheet();
		if ($stylesheet === '') {
			return '';
		}

		// `tokens/<theme>` → `<theme>`.
		$theme = basename($stylesheet);
		if ($theme === '') {
			return '';
		}

		try {
			$relative = $this->themeResolver->logoFileFor(theme: $theme, variant: $variant);

			if ($relative === null) {
				return '';
			}

			// Link against the id this instance actually serves the theme app
			// under, not a compiled-in guess: the app is mid-rename from
			// `nldesign` to `thematiq`, and `linkTo()` will happily build a URL
			// for an id nothing answers to — a 404 logo on an otherwise intact
			// page.
			$themeApp = $this->themeResolver->themeAppId();
			if ($themeApp === null) {
				return '';
			}

			return $this->urlGenerator->linkTo($themeApp, $relative);
		} catch (\Throwable) {
			return '';
		}
	}//end logoUrl()

	/**
	 * The NLDS token stylesheet this app ships for the serving portal, or ''.
	 *
	 * Same fail-quiet posture as `siteThemeStylesheet()`: every failure — no
	 * portal, unknown theme, no token file for it — returns the empty string
	 * and the page renders with the component library's own defaults rather
	 * than another municipality's colours.
	 *
	 * @return string The stylesheet path relative to this app's `css/`, or ''.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function nldsStylesheet(): string {
		try {
			$portal = $this->portalResolver->resolve(
				request: $this->request,
				portalSlug: $this->slug
			);
		} catch (\Throwable) {
			return '';
		}

		if ($portal === null) {
			return '';
		}

		return (string)$this->themeResolver->nldsStylesheetFor(
			theme: (string)($portal['theme'] ?? '')
		);
	}//end nldsStylesheet()
}//end class
