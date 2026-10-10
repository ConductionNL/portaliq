<?php

/**
 * Files a resident sends with an endpoint row action.
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
 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCP\IRequest;

/**
 * The `files` declaration of an endpoint row action, and the uploads it lets through.
 *
 * A row action forwarded only its whitelisted text fields, so a resident who
 * answered a question on their case could not attach the document the answer
 * was about. An action may now declare `files: {field, max?, maxBytes?}`: the
 * dialog offers a file input, the portal reads the uploads under `field`,
 * checks their number and size, and forwards them multipart beside the
 * fields. Nothing else changes: an action without `files` forwards JSON as
 * before, and uploads sent to one are never read.
 *
 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
 */
class RowActionFiles {

	private const FIELD_NAME = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

	/**
	 * Files per forward when the action names no `max`.
	 */
	public const DEFAULT_MAX = 5;

	/**
	 * The most files any action may ask for.
	 */
	public const HARD_MAX = 10;

	/**
	 * Bytes per file when the action names no `maxBytes` (10 MiB).
	 */
	public const DEFAULT_MAX_BYTES = 10485760;

	/**
	 * The most bytes per file any action may allow (25 MiB).
	 */
	public const HARD_MAX_BYTES = 26214400;

	/**
	 * Keep a well-formed `files` declaration on an endpoint row action, drop it otherwise.
	 *
	 * The field must be a plain name that is not a whitelisted text field and
	 * not the row field, so a file part can never stand in for the row id.
	 *
	 * @param array<string, mixed> $action    The action.
	 * @param list<string>         $whitelist The action's text fields.
	 *
	 * @return array<string, mixed> The action, `files` as `{field, max, maxBytes}` or absent.
	 *
	 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
	 */
	public function normalise(array $action, array $whitelist): array {
		$declared = ($action['files'] ?? null);
		unset($action['files']);
		if (is_array($declared) === false) {
			return $action;
		}

		$field = ($declared['field'] ?? null);
		if (is_string($field) === false
			|| preg_match(self::FIELD_NAME, $field) !== 1
			|| in_array($field, $whitelist, true) === true
			|| $field === ($action['rowField'] ?? null)
		) {
			return $action;
		}

		$action['files'] = [
			'field' => $field,
			'max' => $this->bounded(value: ($declared['max'] ?? null), default: self::DEFAULT_MAX, ceiling: self::HARD_MAX),
			'maxBytes' => $this->bounded(value: ($declared['maxBytes'] ?? null), default: self::DEFAULT_MAX_BYTES, ceiling: self::HARD_MAX_BYTES),
		];

		return $action;
	}//end normalise()

	/**
	 * The uploads under the declared field, or the reason they are refused.
	 *
	 * @param array<string, mixed> $action  The normalised action.
	 * @param IRequest             $request The portal request.
	 *
	 * @return array{files: list<array{name: string, type: string, tmp_name: string, size: int}>, error: string|null}
	 *
	 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
	 */
	public function collect(array $action, IRequest $request): array {
		$declared = ($action['files'] ?? null);
		if (is_array($declared) === false || is_string($declared['field'] ?? null) === false) {
			return ['files' => [], 'error' => null];
		}

		$files = $this->uploads(uploaded: $request->getUploadedFile($declared['field']));
		if (count($files) > (int)$declared['max']) {
			return ['files' => [], 'error' => 'too_many_files'];
		}

		foreach ($files as $file) {
			if ($file['size'] > (int)$declared['maxBytes']) {
				return ['files' => [], 'error' => 'file_too_large'];
			}
		}

		return ['files' => $files, 'error' => null];
	}//end collect()

	/**
	 * Flatten one upload or PHP's parallel-array shape into a list; unreadable entries are left out.
	 *
	 * @param mixed $uploaded What `IRequest::getUploadedFile()` answered.
	 *
	 * @return list<array{name: string, type: string, tmp_name: string, size: int}>
	 */
	private function uploads(mixed $uploaded): array {
		if (is_array($uploaded) === false || isset($uploaded['tmp_name']) === false) {
			return [];
		}

		$entries = [$uploaded];
		if (is_array($uploaded['tmp_name']) === true) {
			$entries = [];
			foreach (array_keys($uploaded['tmp_name']) as $key) {
				$entries[] = [
					'name' => ($uploaded['name'][$key] ?? ''),
					'type' => ($uploaded['type'][$key] ?? ''),
					'tmp_name' => ($uploaded['tmp_name'][$key] ?? ''),
					'size' => ($uploaded['size'][$key] ?? 0),
					'error' => ($uploaded['error'][$key] ?? UPLOAD_ERR_OK),
				];
			}
		}

		$files = [];
		foreach ($entries as $entry) {
			$tmpName = (string)($entry['tmp_name'] ?? '');
			if ($tmpName === '' || (int)($entry['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
				continue;
			}

			$files[] = [
				'name' => basename((string)($entry['name'] ?? 'upload')),
				'type' => (string)($entry['type'] ?? 'application/octet-stream'),
				'tmp_name' => $tmpName,
				'size' => (int)($entry['size'] ?? 0),
			];
		}

		return $files;
	}//end uploads()

	/**
	 * A positive integer up to a ceiling, or the default.
	 *
	 * @param mixed $value   The declared value.
	 * @param int   $default The default.
	 * @param int   $ceiling The ceiling.
	 *
	 * @return int
	 */
	private function bounded(mixed $value, int $default, int $ceiling): int {
		if (is_int($value) === false || $value < 1) {
			return $default;
		}

		return min($value, $ceiling);
	}//end bounded()
}//end class
