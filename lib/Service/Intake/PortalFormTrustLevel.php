<?php

/**
 * Portaliq Portal Form Trust Level
 *
 * The sign-in a form needs before it may be shown or accepted. A portal can
 * ask for it for every form, a binding can ask for it for one route, and the
 * published form itself can carry the level its maker chose in buildiq
 * (buildiq#935). The strictest of the three wins, and a level nobody knows
 * wins over every known one, so a typo never widens access (portaliq#725).
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
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Resolves the sign-in level one form needs.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalFormTrustLevel {
	/**
	 * The level a sign-in requirement resolves to when a binding or a form
	 * names a level nobody knows. `PortalSessionService::trustSatisfies()`
	 * refuses it for every subject, so a typo never widens access.
	 */
	public const UNRECOGNISED = 'unrecognised';

	/**
	 * Declared levels that mean "no sign-in needed": an empty value, the `0`
	 * older buildiq forms still carry (buildiq#921) and `anonymous`. `low` is
	 * not one of them: it is the lowest signed-in level, as it is for a
	 * portalPage entry and for requiresIdentifiedIntake (portaliq#731).
	 */
	private const ANONYMOUS_LEVELS = ['', '0', 'anonymous'];

	/**
	 * The sign-in level a submission of this form needs, or null for none.
	 *
	 * Three places can ask for a sign-in: the portal as a whole
	 * (`authentication.requiresIdentifiedIntake`, which needs any session and
	 * so resolves to `low`), the binding (`minTrust`) and the published form
	 * itself (`minTrust`, written by buildiq). The strictest wins. A level
	 * nobody recognises resolves to UNRECOGNISED, which no session
	 * satisfies.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render What render() returned for it.
	 *
	 * @return string|null `low`, `substantial`, `high`, UNRECOGNISED, or
	 *                     null when an anonymous visitor may fill the form in.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function required(array $site, array $binding, array $render): ?string {
		$levels = [];
		if (($site['authentication']['requiresIdentifiedIntake'] ?? false) === true) {
			$levels[] = 'low';
		}

		foreach ([($binding['minTrust'] ?? null), ($render['minTrust'] ?? null)] as $declared) {
			$level = $this->declaredLevel(declared: $declared);
			if ($level !== null) {
				$levels[] = $level;
			}
		}

		$strictest = null;
		foreach ($levels as $level) {
			if ($strictest === null || $this->rank(level: $level) > $this->rank(level: $strictest)) {
				$strictest = $level;
			}
		}

		return $strictest;
	}//end required()

	/**
	 * What one declared level asks for.
	 *
	 * @param mixed $declared The value a binding or form carries.
	 *
	 * @return string|null Null when it asks for no sign-in.
	 */
	private function declaredLevel(mixed $declared): ?string {
		if ($declared === null || $declared === 0 || $declared === false) {
			return null;
		}

		if (is_string($declared) === false) {
			return self::UNRECOGNISED;
		}

		if (in_array($declared, self::ANONYMOUS_LEVELS, true) === true) {
			return null;
		}

		if (in_array($declared, ['low', 'substantial', 'high'], true) === true) {
			return $declared;
		}

		return self::UNRECOGNISED;
	}//end declaredLevel()

	/**
	 * How strict a level is, an unrecognised one strictest of all.
	 *
	 * @param string $level The level.
	 *
	 * @return int
	 */
	private function rank(string $level): int {
		return (['low' => 0, 'substantial' => 1, 'high' => 2][$level] ?? 3);
	}//end rank()
}//end class
