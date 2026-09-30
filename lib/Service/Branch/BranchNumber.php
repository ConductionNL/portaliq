<?php

/**
 * Portaliq branch number
 *
 * The one check on a vestigingsnummer: twelve digits, nothing else.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Branch
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
 * @spec openspec/changes/signin-eherkenning-branch/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Branch;

/**
 * Accepts a vestigingsnummer (12 digits) and turns anything else into ''.
 *
 * A malformed value is dropped, never guessed at: a branch that is not
 * exactly a vestigingsnummer can neither widen nor narrow a session.
 *
 * @spec openspec/changes/signin-eherkenning-branch/tasks.md#T01
 */
class BranchNumber {
	/**
	 * A vestigingsnummer: exactly twelve digits.
	 */
	public const PATTERN = '/^\d{12}$/D';

	/**
	 * The value as a branch number, or '' when it is not one.
	 *
	 * @param mixed $value A claim or request value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/signin-eherkenning-branch/tasks.md#T01
	 */
	public function normalise(mixed $value): string {
		if (is_string($value) === false || preg_match(self::PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end normalise()
}//end class
