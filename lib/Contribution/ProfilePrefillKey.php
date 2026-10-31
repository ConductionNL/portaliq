<?php

/**
 * Portaliq Profile Prefill Key
 *
 * An action field MAY declare `prefill: name` or `prefill: email`: the site
 * then starts that field from the signed-in resident's own name or e-mail
 * address, and the resident can still change it. Only those two sources
 * exist, so a manifest cannot make the form disclose another part of the
 * account. Anything else is dropped.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-action-field-may-start-from-the-signed-in-residents-name-or-e-mail-address
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a known `prefill` source on an action field config, or nothing.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-action-field-may-start-from-the-signed-in-residents-name-or-e-mail-address
 */
class ProfilePrefillKey {
	/**
	 * The field config key.
	 */
	public const KEY = 'prefill';

	/**
	 * The profile values a field may start from.
	 */
	public const SOURCES = ['name', 'email'];

	/**
	 * Copy a known `prefill` source from a declared field config onto a
	 * sanitised one.
	 *
	 * @param array<string, mixed> $entry  The sanitised entry built so far.
	 * @param array<mixed>         $source The declared field config.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-action-field-may-start-from-the-signed-in-residents-name-or-e-mail-address
	 */
	public function apply(array $entry, array $source): array {
		$prefill = ($source[self::KEY] ?? null);
		if (is_string($prefill) === true && in_array($prefill, self::SOURCES, true) === true) {
			$entry[self::KEY] = $prefill;
		}

		return $entry;
	}//end apply()
}//end class
