<?php

/**
 * Which of a portal's content rows speak the language a visitor asked for.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Chooses content rows by their `locale`.
 *
 * A translation is a second row with the same identity (a page's route) and
 * another `locale`. A row without a `locale` is written for every language:
 * every row stored before a portal had a second language is one, and it must
 * keep being served after the second language arrives.
 *
 * Locales are compared trimmed and case-insensitively, so `EN` and `en` are
 * one language; nothing else is normalised (`en-GB` is not `en`), because the
 * portal's own `locales` list is what the request was resolved against.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
 */
class ContentLocale {


	/**
	 * The one row to serve out of several candidates for the same identity.
	 *
	 * In order: the row in the requested locale, the row in the portal's
	 * default locale, a row without a locale, and only then the first row
	 * there is, so a page that exists in some language is never answered as
	 * absent. Among equals the first row wins, as before this choice existed.
	 *
	 * @param array<int, array<string, mixed>> $rows          The candidates.
	 * @param string                           $locale        The requested locale.
	 * @param string                           $defaultLocale The portal's default locale, '' when unknown.
	 *
	 * @return array<string, mixed>|null The row to serve, or null when there is none.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
	 */
	public function pick(array $rows, string $locale, string $defaultLocale): ?array {
		$best     = null;
		$bestRank = PHP_INT_MAX;
		foreach ($rows as $row) {
			$rank = $this->rank(row: $row, locale: $locale, defaultLocale: $defaultLocale);
			if ($rank < $bestRank) {
				$best     = $row;
				$bestRank = $rank;
			}
		}

		return $best;
	}//end pick()


	/**
	 * The rows of a collection (menus, glossary terms) to serve in one locale.
	 *
	 * The collection is translated as a whole: when any row is in the
	 * requested locale, that locale is served; otherwise the portal's default.
	 * Rows without a locale belong to every language and are always kept.
	 * When neither locale has a row and no row is unmarked, the language of
	 * the first row is served rather than nothing, and never a mix.
	 *
	 * @param array<int, array<string, mixed>> $rows          The collection.
	 * @param string                           $locale        The requested locale.
	 * @param string                           $defaultLocale The portal's default locale, '' when unknown.
	 *
	 * @return array<int, array<string, mixed>> The rows to serve, in their original order.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-content-must-be-served-in-the-language-the-visitor-asked-for
	 */
	public function filter(array $rows, string $locale, string $defaultLocale): array {
		$present = array_map(fn (array $row): string => $this->localeOf(row: $row), $rows);

		$chosen = $this->normalise(locale: $defaultLocale);
		if (in_array($this->normalise(locale: $locale), $present, true) === true) {
			$chosen = $this->normalise(locale: $locale);
		}

		$kept = $this->keep(rows: $rows, chosen: $chosen);
		if ($kept === [] && $rows !== []) {
			$kept = $this->keep(rows: $rows, chosen: $present[array_key_first($present)]);
		}

		return $kept;
	}//end filter()


	/**
	 * The rows in one locale, plus those written for every locale.
	 *
	 * @param array<int, array<string, mixed>> $rows   The collection.
	 * @param string                           $chosen The normalised locale to keep.
	 *
	 * @return array<int, array<string, mixed>> The kept rows, reindexed.
	 */
	private function keep(array $rows, string $chosen): array {
		return array_values(
			array_filter(
				$rows,
				function (array $row) use ($chosen): bool {
					$own = $this->localeOf(row: $row);
					return $own === '' || $own === $chosen;
				}
			)
		);
	}//end keep()


	/**
	 * How well a row fits the request; lower is better.
	 *
	 * @param array<string, mixed> $row           The row.
	 * @param string               $locale        The requested locale.
	 * @param string               $defaultLocale The portal's default locale.
	 *
	 * @return int 0 requested, 1 default, 2 unmarked, 3 another language.
	 */
	private function rank(array $row, string $locale, string $defaultLocale): int {
		$own = $this->localeOf(row: $row);
		$ranks = [
			$this->normalise(locale: $locale)        => 0,
			$this->normalise(locale: $defaultLocale) => 1,
		];
		if ($own !== '' && array_key_exists($own, $ranks) === true) {
			return $ranks[$own];
		}

		if ($own === '') {
			return 2;
		}

		return 3;
	}//end rank()


	/**
	 * A row's own locale, normalised; '' when it has none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The locale.
	 */
	private function localeOf(array $row): string {
		$locale = ($row['locale'] ?? '');
		if (is_string($locale) === false) {
			return '';
		}

		return $this->normalise(locale: $locale);
	}//end localeOf()


	/**
	 * A locale as compared here: trimmed and lower-case.
	 *
	 * @param string $locale The locale.
	 *
	 * @return string The comparable form.
	 */
	private function normalise(string $locale): string {
		return strtolower(trim($locale));
	}//end normalise()


}//end class
