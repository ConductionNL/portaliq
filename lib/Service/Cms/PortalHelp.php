<?php

/**
 * Portaliq Portal Help (help-texts-and-form-help)
 *
 * The help details a portal serves the public site: one set for its forms
 * (intro, image, phone and what to say, opening hours, the desk, an e-mail
 * address) and a short text per part of Mijn omgeving. Every value is plain
 * text; an address is an e-mail address, an image a path in the site or an
 * https address. Anything else is left out, so nothing a record holds reaches
 * a visitor as it came.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Holds help details and section help texts to a fixed shape.
 *
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md
 */
class PortalHelp {
	public const SECTIONS = ['overview', 'cases', 'tasks', 'messages', 'contacts'];

	private const KEYS = ['intro', 'phone', 'phoneNote', 'hours', 'desk'];

	private const MAX_TEXT = 1000;

	/**
	 * The help details of a portal, keys left out when empty or malformed.
	 *
	 * @param mixed $help The record's `help`.
	 *
	 * @return array<string, string> Empty when the portal sets none.
	 *
	 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
	 */
	public function details(mixed $help): array {
		if (is_array($help) === false) {
			return [];
		}

		$out = [];
		foreach (self::KEYS as $key) {
			$text = $this->text(value: ($help[$key] ?? null));
			if ($text !== '') {
				$out[$key] = $text;
			}
		}

		$email = $this->text(value: ($help['email'] ?? null));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
			$out['email'] = $email;
		}

		$image = $this->text(value: ($help['image'] ?? null));
		if (preg_match('#^/(?!/)[^\s\\\\]*$#', $image) === 1 || preg_match('#^https://[^\s/]+[^\s]*$#i', $image) === 1) {
			$out['image'] = $image;
		}

		return $out;
	}//end details()

	/**
	 * The help text of each part of Mijn omgeving; unknown parts and empty
	 * texts are left out.
	 *
	 * @param mixed $sections The record's `sectionHelp`.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-page-and-each-part-of-mijn-omgeving-can-carry-a-help-text-req-htf-002
	 */
	public function sections(mixed $sections): array {
		if (is_array($sections) === false) {
			return [];
		}

		$out = [];
		foreach (self::SECTIONS as $key) {
			$text = $this->text(value: ($sections[$key] ?? null));
			if ($text !== '') {
				$out[$key] = $text;
			}
		}

		return $out;
	}//end sections()

	/**
	 * A help text for a page: trimmed, bounded.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-page-and-each-part-of-mijn-omgeving-can-carry-a-help-text-req-htf-002
	 */
	public function pageText(mixed $value): string {
		return $this->text(value: $value);
	}//end pageText()

	/**
	 * @param mixed $value Any value.
	 *
	 * @return string Trimmed text, '' when it is not text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return mb_substr(trim($value), 0, self::MAX_TEXT);
	}//end text()
}//end class
