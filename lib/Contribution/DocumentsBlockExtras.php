<?php

/**
 * Portaliq Documents Block Extras (documents-grouped-per-record)
 *
 * The two keys a documents block may add beside its board keys: the media
 * groups for the signed-in person (`extraGroups`, each a heading and the
 * register and schema of its media) and the words on the bundle button
 * (`bulkDownload`). A key that does not fit is dropped.
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
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a documents block's `extraGroups` and `bulkDownload` when well formed.
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-1
 */
class DocumentsBlockExtras {

	/**
	 * How many groups of media a documents block may add.
	 */
	private const MAX_EXTRA_GROUPS = 5;

	/**
	 * A register or schema slug an extra group may name.
	 */
	private const SLUG = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';

	/**
	 * How long a heading or the button's words may be.
	 */
	private const MAX_LABEL_LENGTH = 120;


	/**
	 * The well-formed `extraGroups`: each a heading and the register and
	 * schema of its media, at most five; a malformed entry is dropped.
	 *
	 * @param mixed $value The declared `extraGroups`.
	 *
	 * @return array<int, array{label: string, register: string, schema: string}>
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-1
	 */
	public function extraGroups(mixed $value): array {
		if (is_array($value) === false || array_is_list($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $group) {
			$entry = $this->extraGroup(group: $group);
			if ($entry !== null && count($out) < self::MAX_EXTRA_GROUPS) {
				$out[] = $entry;
			}
		}

		return $out;
	}//end extraGroups()

	/**
	 * One extra group, or null when its heading, register or schema does not fit.
	 *
	 * @param mixed $group The declared group.
	 *
	 * @return array{label: string, register: string, schema: string}|null
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-1
	 */
	private function extraGroup(mixed $group): ?array {
		if (is_array($group) === false) {
			return null;
		}

		$label    = $this->bundleLabel(value: ($group['label'] ?? null));
		$register = ($group['register'] ?? null);
		$schema   = ($group['schema'] ?? null);
		if ($label === ''
			|| is_string($register) === false || preg_match(self::SLUG, $register) !== 1
			|| is_string($schema) === false || preg_match(self::SLUG, $schema) !== 1
		) {
			return null;
		}

		return ['label' => $label, 'register' => $register, 'schema' => $schema];
	}//end extraGroup()

	/**
	 * A trimmed label no longer than a label over the facts may be, or ''.
	 * The bundle button reads `bulkDownload.label`; a plain string is that label.
	 *
	 * @param mixed $value The declared label, or `{label}`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-1
	 */
	public function bundleLabel(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['label'] ?? null);
		}

		if (is_string($value) === false) {
			return '';
		}

		$text = trim($value);
		if (mb_strlen($text) > self::MAX_LABEL_LENGTH) {
			return '';
		}

		return $text;
	}//end bundleLabel()
}//end class
