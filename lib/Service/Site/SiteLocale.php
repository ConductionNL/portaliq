<?php
/**
 * The language a public site serves: the visitor's, held to the portal's.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Site
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Site;

/**
 * The visitor's language held against the languages the serving portal
 * declares (`locales`). A portal that declares only `nl` serves `nl` to a
 * browser that asks for English: the document language, and so every date
 * the widgets print, follow the portal, not the browser. A portal that
 * declares nothing serves what the visitor asked for.
 *
 * Pure, so the controller's own complexity does not grow with it and a unit
 * test needs no request.
 *
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
 */
final class SiteLocale {


	/**
	 * @param array<string, mixed>|null $portal The serving portal, or null when it could not be resolved.
	 * @param string                    $locale The visitor's language, never empty.
	 *
	 * @return string The language to serve.
	 *
	 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
	 */
	public static function forPortal(?array $portal, string $locale): string {
		$declared = self::declaredLocales(portal: $portal);
		if ($declared === []) {
			return $locale;
		}

		$base = strtolower(substr($locale, 0, 2));
		foreach ($declared as $candidate) {
			if ($candidate === strtolower($locale) || substr($candidate, 0, 2) === $base) {
				return $locale;
			}
		}

		return $declared[0];
	}//end forPortal()


	/**
	 * @param array<string, mixed>|null $portal The portal.
	 *
	 * @return list<string> Its declared locales, lower case, blanks left out.
	 *
	 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
	 */
	private static function declaredLocales(?array $portal): array {
		$declared = [];
		foreach ((array)($portal['locales'] ?? []) as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				$declared[] = strtolower(trim($candidate));
			}
		}

		return $declared;
	}//end declaredLocales()
}//end class
