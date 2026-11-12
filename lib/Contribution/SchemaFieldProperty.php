<?php

/**
 * Portaliq Schema Field Property
 *
 * One whitelisted field of an action and its schema property.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * A field name with the schema property it reads its hints from.
 *
 * @spec openspec/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
final class SchemaFieldProperty {
	/**
	 * Constructor.
	 *
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 */
	public function __construct(
		public readonly string $field,
		public readonly array $property,
	) {
	}//end __construct()
}//end class
