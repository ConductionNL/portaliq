<?php

/**
 * Portaliq Action Answer Fields
 *
 * The fields of a create action whose answers a resident types in: the
 * declared fields, without `claims` (filled from the session) and without
 * file fields (sent as uploads, never as answers).
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\FileFieldConfigNormaliserInterface;

/**
 * Lists a create action's answer fields.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
class ActionAnswerFields {
	/**
	 * The action's answer fields: declared, not `claims`, not a file.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function forAction(array $action): array {
		$files  = $this->fileFields(action: $action);
		$fields = [];
		foreach ((array)($action['fields'] ?? []) as $field) {
			if (is_string($field) === true && $field !== '' && $field !== 'claims' && in_array($field, $files, true) === false) {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end forAction()

	/**
	 * The action's fields configured as a file.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<int, string>
	 */
	private function fileFields(array $action): array {
		$files = [];
		foreach ((array)($action['fieldConfigs'] ?? []) as $field => $config) {
			if (is_array($config) === true && ($config['type'] ?? null) === FileFieldConfigNormaliserInterface::TYPE_FILE) {
				$files[] = (string)$field;
			}
		}

		return $files;
	}//end fileFields()
}//end class
