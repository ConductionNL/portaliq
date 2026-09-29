<?php

/**
 * The public projection of a portal's shell: header shape, footer and regions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\PortalRegionResolver;

/**
 * Chosen fields of the portal record, projected onto named keys.
 *
 * The portal record is not anonymously readable; the content API serves a
 * curated projection of it. This class decides the shell part of that
 * projection, so nothing the record holds reaches the public by accident.
 */
class PortalShell {

	/**
	 * The header shapes a portal may choose. The first is the default.
	 */
	public const HEADER_VARIANTS = ['double', 'single'];

	/**
	 * Constructor.
	 *
	 * @param PortalRegionResolver $regions The closed list of regions.
	 */
	public function __construct(
		private readonly PortalRegionResolver $regions=new PortalRegionResolver(),
	) {
	}//end __construct()

	/**
	 * The header variant: the portal's choice when it is known, else `double`.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string `double` or `single`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 */
	public function headerVariant(array $portal): string {
		$chosen = $portal['headerVariant'] ?? null;
		if (is_string($chosen) === true && in_array($chosen, self::HEADER_VARIANTS, true) === true) {
			return $chosen;
		}

		return self::HEADER_VARIANTS[0];
	}//end headerVariant()

	/**
	 * The public part of the portal's authentication block.
	 *
	 * The modes, always; the register destination and its label only when the
	 * portal declares them. Provider configuration never leaves the record.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `{modes, register?, registerLabel?}`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 */
	public function authentication(array $portal): array {
		$auth   = (array)($portal['authentication'] ?? []);
		$public = ['modes' => array_values((array)($auth['modes'] ?? ['public']))];

		foreach (['register', 'registerLabel'] as $key) {
			$value = trim((string)($auth[$key] ?? ''));
			if ($value !== '') {
				$public[$key] = $value;
			}
		}

		return $public;
	}//end authentication()

	/**
	 * The portal's footer content, on named keys only.
	 *
	 * A social link, legal link or badge without a label or a followable
	 * destination is dropped: a link that leads nowhere, or names nothing, is
	 * worse than no link.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array{description: string, colophon: string, socials: list<array<string, string>>, legalLinks: list<array<string, string>>, badges: list<array<string, string>>}
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
	 */
	public function footer(array $portal): array {
		$footer = $portal['footer'] ?? [];
		if (is_array($footer) === false) {
			$footer = [];
		}

		return [
			'description' => $this->text(value: ($footer['description'] ?? '')),
			'colophon'    => $this->text(value: ($footer['colophon'] ?? '')),
			'socials'     => $this->links(entries: ($footer['socials'] ?? []), extra: 'icon'),
			'legalLinks'  => $this->links(entries: ($footer['legalLinks'] ?? []), extra: null),
			'badges'      => $this->links(entries: ($footer['badges'] ?? []), extra: null),
		];
	}//end footer()

	/**
	 * The portal's own region contents, the middle step of resolution.
	 *
	 * Known regions only, in render order. A present key is kept even when
	 * its list is empty: that is how a portal leaves a region out on every
	 * page (REQ-PTB-009). Each widget is served on named keys, and authored
	 * `style` and `class` never leave the record (REQ-PTB-007).
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, list<array<string, mixed>>> Region name to widgets.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
	 */
	public function regions(array $portal): array {
		$stored = $portal['regions'] ?? [];
		$known  = $this->regions->ordered(regions: (is_array($stored) === true ? $stored : []));

		$served = [];
		foreach ($known as $region => $widgets) {
			$served[$region] = [];
			foreach ($widgets as $index => $widget) {
				$props = (is_array($widget['props'] ?? null) === true ? $widget['props'] : []);
				unset($props['style'], $props['class']);
				$served[$region][] = [
					'id'         => $this->text(value: ($widget['id'] ?? $region.'-'.$index)),
					'widgetKey'  => $this->text(value: ($widget['widgetKey'] ?? '')),
					'slot'       => $region,
					'gridX'      => (int)($widget['gridX'] ?? 0),
					'gridY'      => (int)($widget['gridY'] ?? $index),
					'gridWidth'  => (int)($widget['gridWidth'] ?? 12),
					'gridHeight' => (int)($widget['gridHeight'] ?? 1),
					'props'      => $props,
				];
			}
		}

		return $served;
	}//end regions()

	/**
	 * The entries that carry both a label and a followable destination.
	 *
	 * @param mixed       $entries The authored list.
	 * @param string|null $extra   One more text key an entry may carry.
	 *
	 * @return list<array<string, string>> `{label, href}` entries, plus `$extra` when set.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
	 */
	private function links(mixed $entries, ?string $extra): array {
		$kept = [];
		foreach ((is_array($entries) === true ? $entries : []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$label = $this->text(value: ($entry['label'] ?? ''));
			$href  = $this->text(value: ($entry['href'] ?? ''));
			if ($label === '' || $this->followable(href: $href) === false) {
				continue;
			}

			$link = ['label' => $label, 'href' => $href];
			if ($extra !== null && $this->text(value: ($entry[$extra] ?? '')) !== '') {
				$link[$extra] = $this->text(value: $entry[$extra]);
			}

			$kept[] = $link;
		}

		return $kept;
	}//end links()

	/**
	 * Whether a visitor can follow this destination: an in-site route or a
	 * web, mail or phone address. A `javascript:` or `data:` target is not.
	 *
	 * @param string $href The destination.
	 *
	 * @return bool True when it may be rendered as a link.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
	 */
	private function followable(string $href): bool {
		return preg_match('#^(/(?!/)|https?://|mailto:|tel:)#i', $href) === 1;
	}//end followable()

	/**
	 * A scalar as trimmed text; anything else as empty.
	 *
	 * @param mixed $value The authored value.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
	 */
	private function text(mixed $value): string {
		return is_scalar($value) === true ? trim((string)$value) : '';
	}//end text()
}//end class
