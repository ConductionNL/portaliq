<?php

/**
 * Portaliq Citizen Write Config Normaliser
 *
 * Sanitises the `citizenWrite` declaration a case app puts on a `type: update`
 * action. The declaration says where the case type lives and which field
 * carries the status, and nothing else: the writable set itself is authored on
 * the case type, per field, because D16 puts the portal flag on the field.
 *
 * Fail closed, like every other normaliser here. A malformed or incomplete
 * declaration is dropped whole, and a dropped declaration means the citizen
 * write surface does not exist for that action, not that it is open.
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates the per-action `citizenWrite` declaration, fail closed.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteConfigNormaliser {
	/**
	 * The manifest key carrying the declaration.
	 */
	public const KEY = 'citizenWrite';

	/**
	 * Keys the case app MUST supply; without all three the portal cannot
	 * reach the case type, and a writable set it cannot read is an empty one.
	 */
	private const REQUIRED = ['typeField', 'typeRegister', 'typeSchema'];

	/**
	 * Keys with a default, so a case app declares only what it moves.
	 */
	private const DEFAULTS = [
		'statusField' => 'status',
		'recordField' => 'portalWrites',
		'documentsField' => 'portalDocuments',
	];

	/**
	 * Sanitise the declaration on one action, or drop it.
	 *
	 * Only a `type: update` action may carry it: the three citizen acts all
	 * write to an existing case, and an action that cannot update one has no
	 * case to open a window on.
	 *
	 * @param array<string, mixed> $action The action as declared.
	 *
	 * @return array<string, mixed> The action, with a valid declaration or none.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function normaliseAction(array $action): array {
		if (array_key_exists(self::KEY, $action) === false) {
			return $action;
		}

		$declared = $action[self::KEY];
		if (is_array($declared) === false || ($action['type'] ?? '') !== 'update') {
			unset($action[self::KEY]);
			return $action;
		}

		$config = $this->requiredKeys(declared: $declared);
		if ($config === null) {
			unset($action[self::KEY]);
			return $action;
		}

		$action[self::KEY] = ($config + $this->defaultedKeys(declared: $declared));
		return $action;
	}//end normaliseAction()

	/**
	 * Collect the three keys the case app MUST supply, or fail the lot.
	 *
	 * Split out of normaliseAction() so that method stays under the
	 * complexity threshold; the fail-closed rule is unchanged, and it is
	 * expressed here as a null return rather than by unsetting the key from
	 * a copy of the action this method does not own.
	 *
	 * @param array<string, mixed> $declared The declaration as authored.
	 *
	 * @return array<string, string>|null The three keys, or null if any is
	 *                                    absent, not a string, or empty.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function requiredKeys(array $declared): ?array {
		$config = [];
		foreach (self::REQUIRED as $key) {
			$value = ($declared[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				return null;
			}

			$config[$key] = $value;
		}

		return $config;
	}//end requiredKeys()

	/**
	 * Resolve the keys that carry a default, so a case app declares only
	 * what it moves.
	 *
	 * @param array<string, mixed> $declared The declaration as authored.
	 *
	 * @return array<string, string> Every defaulted key, authored value or
	 *                               fallback.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function defaultedKeys(array $declared): array {
		$config = [];
		foreach (self::DEFAULTS as $key => $fallback) {
			$value = ($declared[$key] ?? null);
			$config[$key] = $fallback;
			if (is_string($value) === true && $value !== '') {
				$config[$key] = $value;
			}
		}

		return $config;
	}//end defaultedKeys()
}//end class
