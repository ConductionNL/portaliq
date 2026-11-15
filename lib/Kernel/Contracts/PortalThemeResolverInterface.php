<?php

/**
 * Contract of PortalThemeResolver
 *
 * The public surface of PortalThemeResolver that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of PortalThemeResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalThemeResolverInterface {

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
	public function stylesheetFor(string $theme): ?string;

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
	public function shippedStylesheets(): array;

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
	public function logoFileFor(string $theme, string $variant=''): ?string;

	/**
	 * Which theme-app id this instance actually answers to.
	 *
	 * @return string|null The installed id, or null when no candidate is.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function themeAppId(): ?string;

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
	public function nldsStylesheetFor(string $theme): ?string;

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
	public function catalogue(): array;
}//end interface
