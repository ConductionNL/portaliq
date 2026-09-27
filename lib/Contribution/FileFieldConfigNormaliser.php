<?php

/**
 * Portaliq File Field Configuration Normaliser (assignment-portal-file-upload)
 *
 * The file half of an action's `fieldConfigs` entry: `type: file` plus the
 * `multiple`, `accept` and `maxSizeMb` keys that shape the picker and the
 * server-side upload check. Fail-closed: a `type` other than `file`, or a file
 * type on anything but a create or update action, is dropped, so the field
 * renders exactly as it did before this change.
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
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-an-action-must-be-able-to-declare-a-file-field
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises the file keys of one field config.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-an-action-must-be-able-to-declare-a-file-field
 */
class FileFieldConfigNormaliser {
	/**
	 * The one field type this vocabulary knows.
	 */
	public const TYPE_FILE = 'file';

	/**
	 * The size limit applied when a file field declares none, in megabytes.
	 */
	public const DEFAULT_SIZE_MB = 20;

	/**
	 * The highest size limit a leaf app may declare, in megabytes.
	 */
	public const MAX_SIZE_MB = 50;

	/**
	 * The most `accept` entries kept per field.
	 */
	private const MAX_ACCEPT = 20;

	/**
	 * Action types a file field may live on: the ones the generic form saves.
	 */
	private const FILE_ACTION_TYPES = ['create', 'update'];

	/**
	 * Add the sanitised file keys to a field-config entry.
	 *
	 * Nothing is added unless the config declares `type: file` and the action
	 * is a create or update action. Then `type` is kept, `multiple` becomes a
	 * strict boolean, `accept` keeps only well-formed extensions and MIME types
	 * (lowercased, at most twenty) and `maxSizeMb` is clamped into 1 to 50.
	 *
	 * @param array<string, mixed> $entry The entry built so far.
	 * @param array<string, mixed> $config The declared field config.
	 * @param string $actionType The action's `type`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-an-action-must-be-able-to-declare-a-file-field
	 */
	public function apply(array $entry, array $config, string $actionType): array {
		if (($config['type'] ?? null) !== self::TYPE_FILE
			|| in_array($actionType, self::FILE_ACTION_TYPES, true) === false
		) {
			return $entry;
		}

		$entry['type'] = self::TYPE_FILE;
		$multiple = ($config['multiple'] ?? false);
		$entry['multiple'] = ($multiple === true || $multiple === 'true' || $multiple === 1);

		$accept = $this->accept(declared: ($config['accept'] ?? null));
		if ($accept !== []) {
			$entry['accept'] = $accept;
		}

		$size = ($config['maxSizeMb'] ?? null);
		if (is_int($size) === true || (is_string($size) === true && ctype_digit($size) === true)) {
			$entry['maxSizeMb'] = max(1, min(self::MAX_SIZE_MB, (int)$size));
		}

		return $entry;
	}//end apply()

	/**
	 * Keep the well-formed `accept` entries: an extension (`.pdf`) or a MIME
	 * type (`application/pdf`, `image/*`), lowercased and de-duplicated.
	 *
	 * @param mixed $declared The declared `accept` value.
	 *
	 * @return array<int, string>
	 */
	private function accept(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$kept = [];
		foreach ($declared as $candidate) {
			if (is_string($candidate) === false) {
				continue;
			}

			$value = strtolower(trim($candidate));
			$isExtension = (preg_match('/^\.[a-z0-9]{1,16}$/', $value) === 1);
			$isMime = (preg_match('/^[a-z]+\/(\*|[a-z0-9][a-z0-9.+-]*)$/', $value) === 1);
			if (($isExtension === true || $isMime === true) && in_array($value, $kept, true) === false) {
				$kept[] = $value;
			}
		}

		return array_slice($kept, 0, self::MAX_ACCEPT);
	}//end accept()
}//end class
