<?php

/**
 * Portaliq Case Status View (case-page-tasks-decision-dates-and-next-step)
 *
 * The public status of a case as its case type labels it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Reads the status label, the status button and the next status from a case type.
 *
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
 */
class CaseStatusView {
	/**
	 * The public status label the case app supplied, rendered as given. The
	 * portal holds no vocabulary of its own, so an unlabelled status yields
	 * empty strings rather than a portal-invented word.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 * @param string $status The case's current status.
	 *
	 * @return array<string, mixed> `value`, `label`, `description`, `action` and `next`.
	 *
	 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
	 */
	public function status(array $caseType, string $status): array {
		$labels = ($caseType[CitizenWritableSetResolver::STATUS_LABELS_PROPERTY] ?? null);
		$entry = null;
		if (is_array($labels) === true) {
			$entry = ($labels[$status] ?? null);
		}

		$extra = [
			'action' => $this->statusAction(caseType: $caseType, status: $status),
			'next'   => $this->nextStatus(labels: $labels, status: $status),
		];
		if (is_array($entry) === false) {
			return ['value' => $status, 'label' => '', 'description' => ''] + $extra;
		}

		$label = ($entry['label'] ?? '');
		$description = ($entry['description'] ?? '');

		if (is_string($label) === false) {
			$label = '';
		}

		if (is_string($description) === false) {
			$description = '';
		}

		return ['value' => $status, 'label' => $label, 'description' => $description] + $extra;
	}//end status()

	/**
	 * The button the current status offers, or null when its entry is missing or malformed.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 * @param string $status The case's current status.
	 *
	 * @return array{label: string, kind: string, target: string}|null
	 *
	 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
	 */
	private function statusAction(array $caseType, string $status): ?array {
		$actions = ($caseType[CitizenWritableSetResolver::STATUS_ACTIONS_PROPERTY] ?? null);
		$entry   = null;
		if (is_array($actions) === true) {
			$entry = ($actions[$status] ?? null);
		}

		if (is_array($entry) === false) {
			return null;
		}

		$label  = ($entry['label'] ?? null);
		$kind   = ($entry['kind'] ?? null);
		$target = ($entry['target'] ?? null);
		if (is_string($label) === false || trim($label) === '' || in_array($kind, ['task', 'page', 'action'], true) === false
			|| is_string($target) === false || trim($target) === ''
		) {
			return null;
		}

		return ['label' => trim($label), 'kind' => $kind, 'target' => trim($target)];
	}//end statusAction()

	/**
	 * The status after the current one, in the order the case type lists its labels.
	 *
	 * @param mixed $labels The case type's status labels.
	 * @param string $status The case's current status.
	 *
	 * @return array{value: string, label: string}|null
	 *
	 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
	 */
	private function nextStatus(mixed $labels, string $status): ?array {
		if (is_array($labels) === false) {
			return null;
		}

		$keys     = array_map('strval', array_keys($labels));
		$position = array_search($status, $keys, true);
		if ($position === false || isset($keys[($position + 1)]) === false) {
			return null;
		}

		$value = $keys[($position + 1)];
		$entry = $labels[$value];
		$label = '';
		if (is_array($entry) === true && is_string($entry['label'] ?? null) === true) {
			$label = $entry['label'];
		}

		if ($label === '') {
			return null;
		}

		return ['value' => $value, 'label' => $label];
	}//end nextStatus()
}//end class
