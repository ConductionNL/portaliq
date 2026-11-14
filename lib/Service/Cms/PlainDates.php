<?php

/**
 * Dates on the plain site page, as a reader writes them.
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Throwable;

/**
 * A stored date (`2026-03-01`, `2026-03-01T10:00:00+00:00`) as a reader in
 * the document language writes it: `1 maart 2026`. The calendar day is read
 * from the value as written, never shifted into another time zone, so a
 * publication dated the first stays dated the first.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 */
class PlainDates {

	/**
	 * Dutch month names, January first.
	 *
	 * @var string[]
	 */
	private const MONTHS_NL = [
		'januari',
		'februari',
		'maart',
		'april',
		'mei',
		'juni',
		'juli',
		'augustus',
		'september',
		'oktober',
		'november',
		'december',
	];

	/**
	 * English month names, January first.
	 *
	 * @var string[]
	 */
	private const MONTHS_EN = [
		'January',
		'February',
		'March',
		'April',
		'May',
		'June',
		'July',
		'August',
		'September',
		'October',
		'November',
		'December',
	];

	/**
	 * The date in words, or '' for a value that is not a date.
	 *
	 * @param string $value  The stored date.
	 * @param string $locale The document language.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
	 */
	public function readable(string $value, string $locale): string {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $match) !== 1) {
			return '';
		}

		try {
			$day = new DateTimeImmutable($match[1] . '-' . $match[2] . '-' . $match[3] . 'T12:00:00', new DateTimeZone('UTC'));
		} catch (Throwable) {
			return '';
		}

		if ($day->format('Y-m-d') !== $match[1] . '-' . $match[2] . '-' . $match[3]) {
			return '';
		}

		$language = 'nl';
		if (str_starts_with(strtolower($locale), 'en') === true) {
			$language = 'en';
		}

		if (class_exists(IntlDateFormatter::class) === false) {
			return $this->inWords(day: $day, language: $language);
		}

		$formatter = new IntlDateFormatter($language, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC');
		$text = $formatter->format($day);
		if (is_string($text) === false || $text === '') {
			return $this->inWords(day: $day, language: $language);
		}

		return $text;
	}//end readable()

	/**
	 * The date in words without the intl extension: `1 maart 2026` in Dutch,
	 * `March 1, 2026` in English, the same text the long intl format gives.
	 *
	 * @param DateTimeImmutable $day      The calendar day.
	 * @param string            $language `nl` or `en`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
	 */
	private function inWords(DateTimeImmutable $day, string $language): string {
		$month = (int) $day->format('n');
		if ($language === 'en') {
			return self::MONTHS_EN[$month - 1] . ' ' . $day->format('j') . ', ' . $day->format('Y');
		}

		return $day->format('j') . ' ' . self::MONTHS_NL[$month - 1] . ' ' . $day->format('Y');
	}//end inWords()
}//end class
