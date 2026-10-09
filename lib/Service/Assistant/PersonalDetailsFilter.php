<?php

/**
 * Portaliq Personal Details Filter
 *
 * Removes citizen service numbers, email addresses and Dutch phone numbers
 * from a question before it leaves.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Assistant
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
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Assistant;

/**
 * A floor, not a guarantee: it catches what is recognisable.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
 */
class PersonalDetailsFilter {

	/**
	 * What replaces a removed detail.
	 *
	 * @var string
	 */
	public const REPLACEMENT = '[removed]';

	/**
	 * Replace the personal details in a text.
	 *
	 * @param string $text The visitor's question.
	 *
	 * @return array{text: string, removed: bool} The cleaned text and whether anything was removed.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
	 */
	public function strip(string $text): array {
		$removed = false;
		$count   = 0;

		$text    = (string)preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::REPLACEMENT, $text, -1, $count);
		$removed = $count > 0;

		$phone   = '/(?<![\d])(?:\+31|0031|0)[\s\-]?(?:\(0\))?[\s\-]?[1-9](?:[\s\-]?\d){8}(?![\d])/';
		$text    = (string)preg_replace($phone, self::REPLACEMENT, $text, -1, $count);
		$removed = $removed || $count > 0;

		$text = (string)preg_replace_callback(
			'/(?<![\d])\d{3}[ .\-]?\d{3}[ .\-]?\d{3}(?![\d])/',
			function (array $match) use (&$removed): string {
				if ($this->passesElevenTest(digits: (string)preg_replace('/\D/', '', $match[0])) === true) {
					$removed = true;
					return self::REPLACEMENT;
				}

				return $match[0];
			},
			$text
		);

		return ['text' => $text, 'removed' => $removed];
	}//end strip()

	/**
	 * The eleven-test of a citizen service number: nine digits, weights 9 to 2
	 * and -1, a sum divisible by eleven and not zero.
	 *
	 * @param string $digits Nine digits.
	 *
	 * @return bool True when the number passes.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
	 */
	public function passesElevenTest(string $digits): bool {
		if (preg_match('/^\d{9}$/', $digits) !== 1) {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 9; $i++) {
			$weight = (9 - $i);
			if ($i === 8) {
				$weight = -1;
			}

			$sum += $weight * (int)$digits[$i];
		}

		return $sum !== 0 && ($sum % 11) === 0;
	}//end passesElevenTest()
}//end class
