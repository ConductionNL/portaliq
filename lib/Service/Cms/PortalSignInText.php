<?php

/**
 * The public text of a portal's sign-in page: a card per way in and the page
 * around the cards (site-chrome-follows-the-design).
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
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Projects `authentication.modeLabels` and `authentication.signInPage` on
 * named keys, plain text only, a link only when it can be followed.
 *
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
 */
class PortalSignInText {

	/**
	 * The ways in a sign-in card may be written for (the portal's modes
	 * besides `public`).
	 */
	public const MODES = ['nextcloud', 'local', 'oidc', 'digid', 'eherkenning', 'eidas', 'email-link'];

	/**
	 * The sign-in keys of an authentication block that say something.
	 *
	 * @param array<array-key, mixed> $auth The portal's authentication block.
	 *
	 * @return array<string, mixed> `modeLabels` and `signInPage`, each only when not empty.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	public function project(array $auth): array {
		$public = [];
		$labels = $this->modeLabels(labels: ($auth['modeLabels'] ?? []));
		if ($labels !== []) {
			$public['modeLabels'] = $labels;
		}

		$page = $this->signInPage(page: ($auth['signInPage'] ?? []));
		if ($page !== []) {
			$public['signInPage'] = $page;
		}

		return $public;
	}//end project()

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
	private function modeLabels(mixed $labels): array {
		if (is_array($labels) === false) {
			return [];
		}

		$kept = [];
		foreach (self::MODES as $mode) {
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
	private function signInPage(mixed $page): array {
		if (is_array($page) === false) {
			return [];
		}

		$kept = $this->texts(source: $page, keys: ['title', 'intro']);

		$notice = $this->texts(source: ($page['notice'] ?? null), keys: ['title', 'text']);
		if (($notice['text'] ?? '') !== '') {
			$kept['notice'] = $notice;
		}

		$staff = $this->texts(source: ($page['staffLink'] ?? null), keys: ['text', 'label', 'href']);
		if (($staff['label'] ?? '') !== '' && $this->followable(href: ($staff['href'] ?? '')) === true) {
			$kept['staffLink'] = $staff;
		}

		$panel = $this->panel(panel: ($page['panel'] ?? null));
		if ($panel !== null) {
			$kept['panel'] = $panel;
		}

		return $kept;
	}//end signInPage()

	/**
	 * The side panel: a title and the points that have a title. Null when
	 * it says nothing.
	 *
	 * @param mixed $panel The authored panel.
	 *
	 * @return array{title: string, items: list<array<string, string>>}|null The panel.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	private function panel(mixed $panel): ?array {
		if (is_array($panel) === false) {
			return null;
		}

		$items = [];
		foreach ((array)($panel['items'] ?? []) as $item) {
			$texts = $this->texts(source: $item, keys: ['title', 'text', 'icon']);
			if (($texts['title'] ?? '') !== '') {
				$items[] = $texts;
			}
		}

		$title = $this->text(value: ($panel['title'] ?? ''));
		if ($title === '' && $items === []) {
			return null;
		}

		return ['title' => $title, 'items' => $items];
	}//end panel()

	/**
	 * The named keys of an authored block that hold text, trimmed.
	 *
	 * @param mixed        $source The block; anything but an array says nothing.
	 * @param list<string> $keys   The keys to keep.
	 *
	 * @return array<string, string> The keys that say something.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	private function texts(mixed $source, array $keys): array {
		$kept = [];
		if (is_array($source) === false) {
			return [];
		}

		foreach ($keys as $key) {
			$value = $this->text(value: ($source[$key] ?? ''));
			if ($value !== '') {
				$kept[$key] = $value;
			}
		}

		return $kept;
	}//end texts()

	/**
	 * Whether a visitor can follow this destination: an in-site route or a
	 * web, mail or phone address.
	 *
	 * @param string $href The destination.
	 *
	 * @return bool True when it may be rendered as a link.
	 *
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
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
	 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
