<?php

/**
 * Every published portal with its theme and whether that theme is readable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Theme
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-themes/spec.md#requirement-the-themes-overview-says-per-portal-whether-its-theme-is-readable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Theme;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalThemeResolver;

/**
 * The rows of the Themes page: a portal, its theme and a contrast verdict.
 *
 * The verdict is PortalThemeContrast's, the one a theme choice is checked
 * with, so the overview and the picker never disagree. It is worked out once
 * per theme, not once per portal.
 */
class PortalThemeOverview {

	/**
	 * Constructor.
	 *
	 * @param PortalResolver      $portals  The published portals.
	 * @param PortalThemeResolver $resolver The theme app's catalogue and tokens.
	 * @param PortalThemeContrast $contrast The contrast verdict per theme.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly PortalThemeResolver $resolver,
		private readonly PortalThemeContrast $contrast,
	) {
	}//end __construct()

	/**
	 * One row per published portal, and how many are hard to read.
	 *
	 * @return array{portals: array<int, array<string, string>>, poorCount: int}
	 *
	 * @spec openspec/specs/portal-themes/spec.md#requirement-the-themes-overview-says-per-portal-whether-its-theme-is-readable
	 */
	public function rows(): array {
		$names = [];
		foreach ($this->resolver->catalogue() as $entry) {
			$names[(string)$entry['id']] = (string)($entry['name'] ?? $entry['id']);
		}

		$verdicts = [];
		$rows     = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === false) {
				continue;
			}

			$theme = (string)($portal['theme'] ?? '');
			if (array_key_exists($theme, $verdicts) === false) {
				$verdicts[$theme] = $this->verdictFor(theme: $theme);
			}

			$rows[] = [
				'id' => (string)($portal['id'] ?? ($portal['@self']['id'] ?? '')),
				'slug' => (string)($portal['slug'] ?? ''),
				'title' => (string)($portal['title'] ?? ''),
				'domain' => $this->firstHostname(portal: $portal),
				'theme' => $theme,
				'themeName' => ($names[$theme] ?? $theme),
				'contrast' => $verdicts[$theme],
				// The value the Themes page's "Change house style" link column labels.
				'houseStyle' => 'change',
			];
		}

		return [
			'portals' => $rows,
			'poorCount' => count(array_filter($rows, static fn (array $row): bool => $row['contrast'] === 'poor')),
		];
	}//end rows()

	/**
	 * `readable`, `poor` or `unchecked` for a theme id.
	 *
	 * Only a verdict that was actually measured reads as readable or poor: no
	 * theme, a theme that does not resolve, a refused one and a missing theme
	 * app are all unchecked, never readable.
	 *
	 * @param string $theme The theme id.
	 *
	 * @return string The verdict.
	 */
	private function verdictFor(string $theme): string {
		if ($theme === '' || $this->resolver->refusalFor(theme: $theme) !== null || $this->resolver->stylesheetFor(theme: $theme) === null) {
			return 'unchecked';
		}

		$verdict = $this->contrast->evaluate(tokens: $this->resolver->tokenValuesFor(theme: $theme));
		if (($verdict['evaluated'] ?? false) !== true) {
			return 'unchecked';
		}

		if (($verdict['passes'] ?? false) === true) {
			return 'readable';
		}

		return 'poor';
	}//end verdictFor()

	/**
	 * The portal's first hostname, or an empty string.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return string The hostname.
	 */
	private function firstHostname(array $portal): string {
		foreach ((array)($portal['domains'] ?? []) as $domain) {
			if (is_array($domain) === false) {
				continue;
			}

			$hostname = (string)($domain['hostname'] ?? '');
			if ($hostname !== '') {
				return $hostname;
			}
		}

		return '';
	}//end firstHostname()
}//end class
