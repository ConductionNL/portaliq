<?php

/**
 * Portaliq Theme Choice
 *
 * The life domains a portal declares, and which of them have something for
 * the signed-in resident.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * A contribution tags a collection or an action with a `theme` slug. The
 * portal decides which slugs exist (`portal.themes`): a tag the portal does not
 * declare is dropped, and a declared theme with nothing tagged for this
 * resident is not announced, so the menu does not list it.
 *
 * @spec openspec/changes/life-domain-theme-pages/specs/portal-themes/spec.md
 */
class ThemeChoice {

	/**
	 * A theme slug, as it appears in an address.
	 */
	private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

	/**
	 * Keep the declared themes that are well formed.
	 *
	 * @param mixed $themes The portal's `themes`.
	 *
	 * @return array<int, array{slug: string, title: string, intro: string, productsLabel: string}> The themes, once each.
	 *
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t01
	 */
	public function declared(mixed $themes): array {
		$out  = [];
		$seen = [];
		foreach ($this->asList(value: $themes) as $theme) {
			if (is_array($theme) === false) {
				continue;
			}

			$slug  = $this->text(value: ($theme['slug'] ?? null));
			$title = $this->text(value: ($theme['title'] ?? null));
			if (preg_match(self::SLUG, $slug) !== 1 || $title === '' || isset($seen[$slug]) === true) {
				continue;
			}

			$seen[$slug] = true;
			$out[]       = [
				'slug'          => $slug,
				'title'         => $title,
				'intro'         => $this->text(value: ($theme['intro'] ?? null)),
				'productsLabel' => $this->text(value: ($theme['productsLabel'] ?? null)),
			];
		}

		return $out;
	}//end declared()

	/**
	 * A value as an array, or an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int|string, mixed>
	 */
	private function asList(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end asList()

	/**
	 * A value as trimmed text, or ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		return '';
	}//end text()

	/**
	 * Drop the tags the portal does not declare and announce the themes with content.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregate.
	 * @param mixed $themes The portal's `themes`.
	 *
	 * @return array<string, mixed> The aggregate; `themes` lists the declared themes that have a collection or action tagged for this resident.
	 *
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
	 */
	public function arrange(array $aggregate, mixed $themes): array {
		$declared = $this->declared(themes: $themes);
		$slugs    = array_column($declared, 'slug');
		$used     = [];
		$contributions = $this->asList(value: ($aggregate['contributions'] ?? null));
		foreach ($contributions as $index => $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			foreach (['collections', 'actions'] as $kind) {
				foreach ($this->asList(value: ($contribution[$kind] ?? null)) as $position => $entry) {
					if (is_array($entry) === false || array_key_exists('theme', $entry) === false) {
						continue;
					}

					if (is_string($entry['theme']) === true && in_array($entry['theme'], $slugs, true) === true) {
						$used[$entry['theme']] = true;
						continue;
					}

					unset($contributions[$index][$kind][$position]['theme']);
				}
			}
		}

		$aggregate['contributions'] = $contributions;
		$aggregate['themes']        = array_values(array_filter($declared, static fn (array $theme): bool => isset($used[$theme['slug']])));

		return $aggregate;
	}//end arrange()
}//end class
