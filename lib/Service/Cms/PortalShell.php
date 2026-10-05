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
use stdClass;

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
	 * The ways in a sign-in card may be written for (the portal's modes
	 * besides `public`).
	 */
	public const SIGN_IN_MODES = ['nextcloud', 'local', 'oidc', 'digid', 'eherkenning', 'eidas'];

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
	 * Every shell field the public site contract serves.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `authentication`, `headerVariant`, `headerSearch`, `accountLabel`, `footer` and `regions`.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
	 */
	public function project(array $portal): array {
		return [
			'authentication' => $this->authentication(portal: $portal),
			'headerVariant'  => $this->headerVariant(portal: $portal),
			'headerSearch'   => $this->headerSearch(portal: $portal),
			'accountLabel'   => $this->text(value: ($portal['accountLabel'] ?? '')),
			'footer'         => $this->footer(portal: $portal),
			'regions'        => $this->publicRegions(portal: $portal),
		];
	}//end project()

	/**
	 * The search box in the header: whether it shows, its hint and the
	 * portal's search page. A route that is not an in-site path falls back to
	 * `/zoeken`, the page the hero search has always opened.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array{enabled: bool, placeholder: string, route: string} The box.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
	 */
	public function headerSearch(array $portal): array {
		$search = $portal['headerSearch'] ?? [];
		if (is_array($search) === false) {
			$search = [];
		}

		$route = $this->text(value: ($search['route'] ?? ''));
		if (preg_match('#^/(?!/)#', $route) !== 1) {
			$route = '/zoeken';
		}

		return [
			'enabled'     => ($search['enabled'] ?? false) === true,
			'placeholder' => $this->text(value: ($search['placeholder'] ?? '')),
			'route'       => $route,
		];
	}//end headerSearch()

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
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
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

		$labels = $this->modeLabels(labels: ($auth['modeLabels'] ?? []));
		if ($labels !== []) {
			$public['modeLabels'] = $labels;
		}

		$page = $this->signInPage(page: ($auth['signInPage'] ?? []));
		if ($page !== []) {
			$public['signInPage'] = $page;
		}

		return $public;
	}//end authentication()

	/**
	 * The sign-in card per way in: title, text, button, hint and icon, each
	 * plain text. A mode the portal could not offer is dropped; so is a card
	 * that says nothing.
	 *
	 * @param mixed $labels The authored map, mode to card.
	 *
	 * @return array<string, array<string, string>> Mode to `{title?, text?, button?, hint?, icon?}`.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	public function modeLabels(mixed $labels): array {
		if (is_array($labels) === false) {
			return [];
		}

		$kept = [];
		foreach (self::SIGN_IN_MODES as $mode) {
			$card = $labels[$mode] ?? null;
			if (is_array($card) === false) {
				continue;
			}

			$texts = $this->texts(source: $card, keys: ['title', 'text', 'button', 'hint', 'icon']);
			if ($texts !== []) {
				$kept[$mode] = $texts;
			}
		}

		return $kept;
	}//end modeLabels()

	/**
	 * The text around the sign-in cards: the page's title and intro, a
	 * notice under the cards, a line for staff with its link, and a side
	 * panel of points. Plain text; a link only when it can be followed.
	 *
	 * @param mixed $page The authored block.
	 *
	 * @return array<string, mixed> The parts that say something.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	public function signInPage(mixed $page): array {
		if (is_array($page) === false) {
			return [];
		}

		$kept = $this->texts(source: $page, keys: ['title', 'intro']);

		$notice = (is_array($page['notice'] ?? null) === true) ? $this->texts(source: $page['notice'], keys: ['title', 'text']) : [];
		if (($notice['text'] ?? '') !== '') {
			$kept['notice'] = $notice;
		}

		$staff = (is_array($page['staffLink'] ?? null) === true) ? $this->texts(source: $page['staffLink'], keys: ['text', 'label', 'href']) : [];
		if (($staff['label'] ?? '') !== '' && $this->followable(href: ($staff['href'] ?? '')) === true) {
			$kept['staffLink'] = $staff;
		}

		$panel = $page['panel'] ?? null;
		if (is_array($panel) === true) {
			$items = [];
			foreach ((array)($panel['items'] ?? []) as $item) {
				$texts = (is_array($item) === true) ? $this->texts(source: $item, keys: ['title', 'text', 'icon']) : [];
				if (($texts['title'] ?? '') !== '') {
					$items[] = $texts;
				}
			}

			$title = $this->text(value: ($panel['title'] ?? ''));
			if ($title !== '' || $items !== []) {
				$kept['panel'] = ['title' => $title, 'items' => $items];
			}
		}

		return $kept;
	}//end signInPage()

	/**
	 * The named keys of an authored block that hold text, trimmed.
	 *
	 * @param array<array-key, mixed> $source The block.
	 * @param list<string>            $keys   The keys to keep.
	 *
	 * @return array<string, string> The keys that say something.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	private function texts(array $source, array $keys): array {
		$kept = [];
		foreach ($keys as $key) {
			$value = $this->text(value: ($source[$key] ?? ''));
			if ($value !== '') {
				$kept[$key] = $value;
			}
		}

		return $kept;
	}//end texts()

	/**
	 * The portal's footer content, on named keys only.
	 *
	 * A social link, legal link or badge without a label or a followable
	 * destination is dropped: a link that leads nowhere, or names nothing, is
	 * worse than no link.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `{description, colophon, socials, legalLinks, badges, cta, contact}`; each list holds `{label, href}` entries.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
	 */
	public function footer(array $portal): array {
		$footer = $portal['footer'] ?? [];
		if (is_array($footer) === false) {
			$footer = [];
		}

		$cta = $this->links(entries: [($footer['cta'] ?? null)], extra: null);

		return [
			'description' => $this->text(value: ($footer['description'] ?? '')),
			'colophon'    => $this->text(value: ($footer['colophon'] ?? '')),
			'socials'     => $this->links(entries: ($footer['socials'] ?? []), extra: 'icon'),
			'legalLinks'  => $this->links(entries: ($footer['legalLinks'] ?? []), extra: null),
			'badges'      => $this->links(entries: ($footer['badges'] ?? []), extra: null),
			'cta'         => ($cta[0] ?? null),
			'contact'     => $this->contact(contact: ($footer['contact'] ?? null)),
		];
	}//end footer()

	/**
	 * The footer's contact column: a title and plain lines, a line with a
	 * followable `href` rendered as a link. Null when it has no line.
	 *
	 * @param mixed $contact The authored column.
	 *
	 * @return array{title: string, lines: list<array<string, string>>}|null The column.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
	 */
	public function contact(mixed $contact): ?array {
		if (is_array($contact) === false) {
			return null;
		}

		$lines = [];
		foreach ((array)($contact['lines'] ?? []) as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$text = $this->text(value: ($line['text'] ?? ''));
			if ($text === '') {
				continue;
			}

			$href = $this->text(value: ($line['href'] ?? ''));
			$lines[] = ($this->followable(href: $href) === true) ? ['text' => $text, 'href' => $href] : ['text' => $text];
		}

		if ($lines === []) {
			return null;
		}

		return ['title' => $this->text(value: ($contact['title'] ?? '')), 'lines' => $lines];
	}//end contact()

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
		if (is_array($stored) === false) {
			$stored = [];
		}

		$known = $this->regions->ordered(regions: $stored);

		$served = [];
		foreach ($known as $region => $widgets) {
			$served[$region] = [];
			foreach ($widgets as $index => $widget) {
				$props = ($widget['props'] ?? []);
				if (is_array($props) === false) {
					$props = [];
				}

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
	 * The portal's regions for the JSON response: an empty map stays `{}`.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed>|stdClass Region name to widgets.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
	 */
	public function publicRegions(array $portal): array|stdClass {
		return $this->regions->forJson(regions: $this->regions(portal: $portal));
	}//end publicRegions()

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
		if (is_array($entries) === false) {
			return [];
		}

		foreach ($entries as $entry) {
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
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
