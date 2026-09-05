<?php

/**
 * Portaliq Traffic Form Stats.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Counts what happened to each form on a day: how often it was started,
 * submitted and abandoned, how long each field held focus, and which field
 * people were on when they left.
 *
 * NOTHING HERE READS A VALUE, because none is stored: the validator keeps
 * only the form id, the field id, the last field id and the milliseconds
 * on a form event. What this class can say is therefore "the postcode
 * field takes forty seconds and a third of the people who leave, leave
 * there", which is what a form designer needs and all a traffic report
 * should know.
 *
 * Pure: sessions in, rows out.
 *
 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
 */
class TrafficFormStats {

	/**
	 * The most forms and the most fields per form a rollup carries.
	 */
	private const TOP = 50;

	/**
	 * The form rows for the rollup, ranked by starts.
	 *
	 * @param array<int, array<string, mixed>> $sessions The day's sessions.
	 *
	 * @return array<int, array<string, mixed>> Rows of `{formId, starts, submits, abandons, completionRate, fields}`.
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
	 */
	public function rows(array $sessions): array {
		$forms = [];
		foreach ($sessions as $session) {
			foreach (($session['events'] ?? []) as $event) {
				$this->count(forms: $forms, event: $event);
			}
		}

		$out = [];
		foreach ($forms as $formId => $form) {
			$out[] = [
				'formId' => (string)$formId,
				'starts' => $form['starts'],
				'submits' => $form['submits'],
				'abandons' => $form['abandons'],
				'completionRate' => $this->share(part: $form['submits'], whole: $form['starts']),
				'fields' => $this->fields(fields: $form['fields']),
			];
		}

		usort($out, static fn (array $a, array $b): int => [$b['starts'], $a['formId']] <=> [$a['starts'], $b['formId']]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * A share, rounded, and 0 for an empty whole rather than a division
	 * by zero.
	 *
	 * @param int $part     The numerator.
	 * @param int $whole    The denominator.
	 * @param int $decimals Decimals kept.
	 *
	 * @return float The share.
	 */
	private function share(int $part, int $whole, int $decimals = 3): float {
		if ($whole <= 0) {
			return 0.0;
		}

		return round($part / $whole, $decimals);
	}

	/**
	 * Add one event to the per-form tallies, when it is a form event.
	 *
	 * @param array<string, array<string, mixed>> $forms The tallies, by form id.
	 * @param array<string, mixed>                $event The stored event.
	 *
	 * @return void
	 */
	private function count(array &$forms, array $event): void {
		$name = (string)($event['name'] ?? '');
		$params = $event['params'] ?? [];
		if (is_array($params) === false) {
			$params = [];
		}

		$formId = trim((string)($params['formId'] ?? ''));
		if ($formId === '' || in_array($name, ['form_start', 'form_field', 'form_abandon', 'form_submit'], true) === false) {
			return;
		}

		if (isset($forms[$formId]) === false) {
			$forms[$formId] = ['starts' => 0, 'submits' => 0, 'abandons' => 0, 'fields' => []];
		}

		$form = &$forms[$formId];
		if ($name === 'form_start') {
			$form['starts']++;
			return;
		}

		if ($name === 'form_submit') {
			$form['submits']++;
			return;
		}

		if ($name === 'form_abandon') {
			$form['abandons']++;
			$this->abandonedAt(fields: $form['fields'], fieldId: trim((string)($params['lastFieldId'] ?? '')));
			return;
		}

		$this->visited(
			fields: $form['fields'],
			fieldId: trim((string)($params['fieldId'] ?? '')),
			millis: max(0, (int)($params['ms'] ?? 0))
		);
	}

	/**
	 * Note an abandon on its last field, when one was named.
	 *
	 * @param array<string, array<string, int>> $fields  The form's fields.
	 * @param string                            $fieldId The last field, or ''.
	 *
	 * @return void
	 */
	private function abandonedAt(array &$fields, string $fieldId): void {
		if ($fieldId === '') {
			return;
		}

		$this->ensure(fields: $fields, fieldId: $fieldId);
		$fields[$fieldId]['abandonedHere']++;
	}

	/**
	 * Note a visit to a field and the time it held focus.
	 *
	 * @param array<string, array<string, int>> $fields  The form's fields.
	 * @param string                            $fieldId The field, or ''.
	 * @param int                               $millis  Milliseconds in focus.
	 *
	 * @return void
	 */
	private function visited(array &$fields, string $fieldId, int $millis): void {
		if ($fieldId === '') {
			return;
		}

		$this->ensure(fields: $fields, fieldId: $fieldId);
		$fields[$fieldId]['ms'] += $millis;
		$fields[$fieldId]['visits']++;
	}

	/**
	 * Create a field's tally on first sight.
	 *
	 * @param array<string, array<string, int>> $fields  The form's fields.
	 * @param string                            $fieldId The field.
	 *
	 * @return void
	 */
	private function ensure(array &$fields, string $fieldId): void {
		if (isset($fields[$fieldId]) === false) {
			$fields[$fieldId] = ['ms' => 0, 'visits' => 0, 'abandonedHere' => 0];
		}
	}

	/**
	 * The field rows, the ones most left at first.
	 *
	 * @param array<string, array<string, int>> $fields The tallies.
	 *
	 * @return array<int, array<string, mixed>> Rows of `{fieldId, avgMs, abandonedHere}`.
	 */
	private function fields(array $fields): array {
		$out = [];
		foreach ($fields as $fieldId => $field) {
			$out[] = [
				'fieldId' => (string)$fieldId,
				'avgMs' => (int)round($this->share(part: $field['ms'], whole: $field['visits'], decimals: 0)),
				'abandonedHere' => $field['abandonedHere'],
			];
		}

		usort($out, static fn (array $a, array $b): int => [$b['abandonedHere'], $a['fieldId']] <=> [$a['abandonedHere'], $b['fieldId']]);

		return array_slice($out, 0, self::TOP);
	}
}
