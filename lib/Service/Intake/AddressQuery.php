<?php

/**
 * Portaliq Address Query
 *
 * The address a lookup asks the register for.
 *
 * @category Service
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * A normalised postcode and a house number, letter and addition.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */
final class AddressQuery {
	/**
	 * Constructor.
	 *
	 * @param string $postcode The normalised postcode.
	 * @param string $number   The house number, digits only.
	 * @param string $letter   The house letter, or ''.
	 * @param string $addition The house number addition, or ''.
	 */
	public function __construct(
		public readonly string $postcode,
		public readonly string $number,
		public readonly string $letter,
		public readonly string $addition,
	) {
	}//end __construct()
}//end class
