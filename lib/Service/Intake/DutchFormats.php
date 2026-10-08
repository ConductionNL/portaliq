<?php

/**
 * Portaliq Dutch Formats (data-lookups-and-checks-in-forms)
 *
 * The format checks a form field may ask for with `format`: BSN, IBAN, licence
 * plate, Dutch and international phone numbers, postcode, KvK number and
 * branch number. The site mirrors this file in
 * `src/site/components/forms/formats.js`; both read the same fixtures in
 * `tests/fixtures/dutch-formats.json`, so the screen and the server cannot
 * disagree about what is valid.
 *
 * @category Intake
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Checks and normalises a value against a named Dutch format.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
 */
class DutchFormats {
	/**
	 * The formats a field may name.
	 *
	 * @var string[]
	 */
	public const FORMATS = ['bsn', 'iban', 'nl-licence-plate', 'phone-nl', 'phone-international', 'postcode', 'kvk', 'kvk-branch'];

	/**
	 * RDW side codes 1 to 14 as letter (L) and digit (D) runs.
	 *
	 * @var string[]
	 */
	private const PLATE_PATTERNS = [
		'LLDDDD', 'DDDDLL', 'DDLLDD', 'LLDDLL', 'LLLLDD', 'DDLLLL', 'DDLLLD', 'DLLLDD', 'LLDDDL', 'LDDDLL', 'LLLDDL', 'LDDLLL', 'DLLDDD', 'DDDLLD',
	];

	/**
	 * Whether a string names a format this class checks.
	 *
	 * @param string $format The format name.
	 *
	 * @return bool
	 */
	public function knows(string $format): bool {
		return in_array($format, self::FORMATS, true);
	}//end knows()

	/**
	 * The value in its stored form when it is valid, or null when it is not.
	 *
	 * @param string $format The format name.
	 * @param string $value  The submitted value.
	 *
	 * @return string|null The normalised value, or null.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
	 */
	public function normalise(string $format, string $value): ?string {
		$value = trim($value);
		switch ($format) {
			case 'bsn':
				return $this->bsn(value: $value);
			case 'iban':
				return $this->iban(value: $value);
			case 'nl-licence-plate':
				return $this->plate(value: $value);
			case 'phone-nl':
				return $this->phoneNl(value: $value);
			case 'phone-international':
				return $this->phoneInternational(value: $value);
			case 'postcode':
				return $this->postcode(value: $value);
			case 'kvk':
				return $this->digits(value: $value, length: 8);
			case 'kvk-branch':
				return $this->digits(value: $value, length: 12);
			default:
				return null;
		}
	}//end normalise()

	/**
	 * Exactly `length` digits.
	 *
	 * @param string $value  The value.
	 * @param int    $length The number of digits.
	 *
	 * @return string|null
	 */
	private function digits(string $value, int $length): ?string {
		if (preg_match('/^\d{' . $length . '}$/', $value) !== 1) {
			return null;
		}

		return $value;
	}//end digits()

	/**
	 * A BSN: nine digits that pass the elfproef.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private function bsn(string $value): ?string {
		if (preg_match('/^\d{9}$/', $value) !== 1 || $value === '000000000') {
			return null;
		}

		$sum = 0;
		for ($index = 0; $index < 9; $index++) {
			$weight = (9 - $index);
			if ($index === 8) {
				$weight = -1;
			}

			$sum += ($weight * (int)$value[$index]);
		}

		if (($sum % 11) !== 0) {
			return null;
		}

		return $value;
	}//end bsn()

	/**
	 * An IBAN: ISO 13616 mod 97, with the Dutch length of 18.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private function iban(string $value): ?string {
		$iban = strtoupper((string)preg_replace('/\s+/', '', $value));
		if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
			return null;
		}

		if (str_starts_with($iban, 'NL') === true && strlen($iban) !== 18) {
			return null;
		}

		$rearranged = (substr($iban, 4) . substr($iban, 0, 4));
		$remainder  = 0;
		foreach (str_split($rearranged) as $char) {
			$number = $char;
			if (ctype_alpha($char) === true) {
				$number = (string)(ord($char) - 55);
			}

			$remainder = (int)(((string)$remainder . $number) % 97);
		}

		if ($remainder !== 1) {
			return null;
		}

		return $iban;
	}//end iban()

	/**
	 * A licence plate in one of the RDW side codes, dashes optional.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null Capitals, no dashes.
	 */
	private function plate(string $value): ?string {
		$plate = strtoupper((string)preg_replace('/[\s-]+/', '', $value));
		if (preg_match('/^[A-Z0-9]{6}$/', $plate) !== 1) {
			return null;
		}

		$shape = (string)preg_replace(['/[A-Z]/', '/\d/'], ['L', 'D'], $plate);
		$code  = array_search($shape, self::PLATE_PATTERNS, true);
		if ($code === false) {
			return null;
		}

		// From side code 7 on, plates use consonants only.
		if ($code >= 6 && preg_match('/[AEIOUCQ]/', $plate) === 1) {
			return null;
		}

		return $plate;
	}//end plate()

	/**
	 * A Dutch phone number, national or with +31 / 0031.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null Spaces, dashes and brackets removed.
	 */
	private function phoneNl(string $value): ?string {
		$number = (string)preg_replace('/[\s\-()]+/', '', $value);
		if (preg_match('/^(0[1-9]\d{8}|(\+|00)31[1-9]\d{8})$/', $number) !== 1) {
			return null;
		}

		return $number;
	}//end phoneNl()

	/**
	 * An international phone number in E.164 form.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private function phoneInternational(string $value): ?string {
		$number = (string)preg_replace('/[\s\-()]+/', '', $value);
		if (preg_match('/^\+[1-9]\d{6,14}$/', $number) !== 1) {
			return null;
		}

		return $number;
	}//end phoneInternational()

	/**
	 * A Dutch postcode, the space optional.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null "1234 AB".
	 */
	private function postcode(string $value): ?string {
		if (preg_match('/^([1-9]\d{3})\s?([A-Za-z]{2})$/', $value, $match) !== 1) {
			return null;
		}

		$letters = strtoupper($match[2]);
		if (in_array($letters, ['SA', 'SD', 'SS'], true) === true) {
			return null;
		}

		return $match[1] . ' ' . $letters;
	}//end postcode()
}//end class
