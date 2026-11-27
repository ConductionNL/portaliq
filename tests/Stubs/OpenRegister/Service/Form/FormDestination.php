<?php

/**
 * OpenRegister test stub: openregister's FormDestination on main, loaded only
 * when OpenRegister is absent (tests/bootstrap-unit.php). The schema is typed
 * `object` here because OpenRegister's Schema entity is not loadable then.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test stub of an OpenRegister class
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * The destination a form's mapping is judged against: the schema, and the
 * form's audience (`public` or `authenticated`; null for an internal form).
 */
final class FormDestination
{
	/**
	 * @param object      $schema   The destination schema.
	 * @param string|null $audience The form's audience.
	 */
	public function __construct(
		public readonly object $schema,
		public readonly ?string $audience = null,
	) {
	}//end __construct()
}//end class
