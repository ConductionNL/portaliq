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
	 * The words a breadcrumb may name the page on screen by. The first is the default.
	 */
	public const BREADCRUMB_WORDS = ['menu', 'page'];


	/**
	 * Constructor.
	 *
	 * @param PortalRegionResolver $regions      The closed list of regions.
	 * @param PortalSignInText     $signInText   The sign-in page's text.
	 * @param PortalResidentMenu   $residentMenu The resident menu's card label, groups and left-out items.
	 * @param PortalHelp           $help         The help details and section help texts.
	 */
	public function __construct(
		private readonly PortalRegionResolver $regions=new PortalRegionResolver(),
		private readonly PortalSignInText $signInText=new PortalSignInText(),
		private readonly PortalResidentMenu $residentMenu=new PortalResidentMenu(),
		private readonly PortalHelp $help=new PortalHelp(),
	) {
	}//end __construct()

	/**
	 * Every shell field the public site contract serves.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `authentication`, `headerVariant`, `headerSearch`, `accountLabel`, `breadcrumb`,
	 *                              `residentMenu`, `footer` and `regions`.
	 *
	 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-whom-the-resident-acts-for
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
	 * @spec openspec/changes/site-breadcrumb-follows-the-school-boards/specs/site-look/spec.md#requirement-a-portal-chooses-the-words-of-the-last-crumb
	 */
	public function project(array $portal): array {
		return [
			'authentication' => $this->authentication(portal: $portal),
			'headerVariant'  => $this->headerVariant(portal: $portal),
			'headerSearch'   => $this->headerSearch(portal: $portal),
			'accountLabel'   => $this->text(value: ($portal['accountLabel'] ?? '')),
			'breadcrumb'     => $this->breadcrumb(portal: $portal),
			'residentMenu'   => $this->residentMenu->project(portal: $portal),
			// How Mijn zaken draws its list (zuiddrecht-resident-pages-match-the-boards).
			'myCases'        => $this->myCases(portal: $portal),
			'footer'         => $this->footer(portal: $portal),
			'regions'        => $this->publicRegions(portal: $portal),
			// The portal's help for its forms and for each part of Mijn omgeving
			// (help-texts-and-form-help).
			'help'           => $this->help->details(help: ($portal['help'] ?? null)),
			'sectionHelp'    => $this->help->sections(sections: ($portal['sectionHelp'] ?? null)),
			// The kind of organisation, for "Van het waterschap", the footer
			// and DCTERMS.creator (portal-identity-from-the-admin REQ-PIA-003).
			'organisation'   => $this->organisation(portal: $portal),
		];
	}//end project()

	/**
	 * The organisation that runs the portal: its name, and its kind as the TOOI
	 * uri with the label picked beside it. A kind that is not a uri is no TOOI
	 * concept, so both type and label read '' and the site says "de organisatie".
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array{type: string, label: string, name: string}
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function organisation(array $portal): array {
		$type  = $this->text(value: ($portal['organisationType'] ?? ''));
		$label = $this->text(value: ($portal['organisationTypeLabel'] ?? ''));
		if (preg_match('#^https?://#i', $type) !== 1 || $label === '') {
			$type  = '';
			$label = '';
		}

		return ['type' => $type, 'label' => $label, 'name' => $this->text(value: ($portal['title'] ?? ''))];
	}//end organisation()

	/**
	 * How Mijn zaken draws its list: `{display: rows}` when the portal says
	 * so, else [] (zuiddrecht-resident-pages-match-the-boards).
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 */
	private function myCases(array $portal): array {
		$cases = $portal['myCases'] ?? [];
		if (is_array($cases) === true && ($cases['display'] ?? null) === 'rows') {
			return ['display' => 'rows'];
		}

		return [];
	}//end myCases()

	/**
	 * The search box in the header: whether it shows (a declared box shows
	 * unless `enabled` is false), its accessible name, its hint and the
	 * portal's search page. A route that is not an in-site path falls back to
	 * `/zoeken`, the page the hero search has always opened.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array{enabled: bool, label: string, placeholder: string, route: string} The box.
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

		// Declared is on: a portal that writes the box wants it, unless it
		// says `enabled: false` (lane L3 declares `label` and `route` only).
		$declared = (is_array($portal['headerSearch'] ?? null) === true && $search !== []);

		return [
			'enabled'     => $declared === true && ($search['enabled'] ?? true) === true,
			'label'       => $this->text(value: ($search['label'] ?? '')),
			'placeholder' => $this->text(value: ($search['placeholder'] ?? '')),
			'route'       => $route,
		];
	}//end headerSearch()

	/**
	 * The words of the breadcrumb's last crumb: the portal's choice when it
	 * is known, else `menu` (site-breadcrumb-follows-the-school-boards).
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string `menu` or `page`.
	 *
	 * @spec openspec/changes/site-breadcrumb-follows-the-school-boards/specs/site-look/spec.md#requirement-a-portal-chooses-the-words-of-the-last-crumb
	 */
	public function breadcrumb(array $portal): string {
		$chosen = $portal['breadcrumb'] ?? null;
		if (is_string($chosen) === true && in_array($chosen, self::BREADCRUMB_WORDS, true) === true) {
			return $chosen;
		}

		return self::BREADCRUMB_WORDS[0];
	}//end breadcrumb()

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

		return $public + $this->signInText->project(auth: $auth);
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
	private function contact(mixed $contact): ?array {
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

			$kept = ['text' => $text];
			$href = $this->text(value: ($line['href'] ?? ''));
			if ($this->followable(href: $href) === true) {
				$kept['href'] = $href;
			}

			$lines[] = $kept;
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
