<?php

/**
 * Portaliq Site Creator
 *
 * The site head's `DCTERMS.creator`: the organisation that runs the portal,
 * with its kind from the portal's TOOI organisation type label; and the
 * footer's line saying whose website it is ("Een website van het waterschap").
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Words the creator of a portal's pages.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
final class SiteCreator {

	/**
	 * "Waterschap Hollands Noorderkwartier (waterschap)": the organisation's
	 * name, else the portal's title, with the type label when there is one.
	 * '' when there is no name at all.
	 *
	 * @param array<string, mixed> $portal       The resolved portal.
	 * @param array{name?: string}|null $organisation The portal's organisation, or null when unknown.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public static function content(array $portal, ?array $organisation): string {
		$name = trim((string)($organisation['name'] ?? ''));
		if ($name === '') {
			$name = trim((string)($portal['title'] ?? ''));
		}

		$kind = trim((string)($portal['organisationTypeLabel'] ?? ''));
		if ($name === '' || $kind === '') {
			return $name;
		}

		return $name . ' (' . $kind . ')';
	}//end content()

	/**
	 * The Dutch nouns among the organisation types that take "het"; every
	 * other kind takes "de".
	 */
	private const HET = ['waterschap', 'ministerie'];

	/**
	 * The footer's line naming whose website this is: "Een website van het
	 * waterschap". The type label is Dutch, so only a Dutch page names it;
	 * a portal without a type, or a page in another language, has none ('').
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 * @param string               $locale The page language.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function kindLine(array $portal, string $locale): string {
		$kind = mb_strtolower(trim((string)($portal['organisationTypeLabel'] ?? '')));
		if ($kind === '' || str_starts_with(strtolower($locale), 'nl') === false) {
			return '';
		}

		$article = 'de';
		if (in_array($kind, self::HET, true) === true) {
			$article = 'het';
		}

		return 'Een website van ' . $article . ' ' . $kind;
	}//end kindLine()
}//end class
