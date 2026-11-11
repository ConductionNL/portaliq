<?php

/**
 * Portaliq Traffic Rollup Outcomes.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Sums the members' goals, experiments, funnels and forms for a roll-up,
 * and finishes them into the roll-up record's rows. Part of
 * TrafficRollupSum.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */
final class TrafficRollupOutcomes {

	/**
	 * Constructor.
	 *
	 * @param TrafficExperiments  $experiments Re-derives a summed experiment's verdict.
	 * @param TrafficRollupValues $values      Reads stored numbers and rounds ratios.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficExperiments $experiments,
		private readonly TrafficRollupValues $values = new TrafficRollupValues(),
	) {
	}

	/**
	 * Merge goal rows by id.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's goals.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function addGoals(array $into, mixed $rows): array {
		foreach ($this->keyedRows(rows: $rows, key: 'id') as [$id, $row]) {
			$goal = $into[$id] ?? ['id' => $id, 'name' => (string)($row['name'] ?? $id), 'conversions' => 0, 'completions' => 0, 'value' => 0.0];
			$goal['conversions'] += $this->values->int(value: $row['conversions'] ?? 0);
			$goal['completions'] += $this->values->int(value: $row['completions'] ?? 0);
			$goal['value'] = round($goal['value'] + $this->values->float(value: $row['value'] ?? 0), 2);
			$into[$id] = $goal;
		}

		return $into;
	}
	/**
	 * Merge experiment rows by id, variants by id
	 * (portal-traffic-experiments).
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's experiments.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function addExperiments(array $into, mixed $rows): array {
		foreach ($this->keyedRows(rows: $rows, key: 'id') as [$id, $row]) {
			$experiment = $into[$id] ?? ['id' => $id, 'name' => $id, 'status' => 'running', 'variants' => []];
			$experiment['name'] = (string)($row['name'] ?? $experiment['name']);
			$experiment['status'] = (string)($row['status'] ?? $experiment['status']);
			foreach ($this->keyedRows(rows: (array)($row['variants'] ?? []), key: 'id') as [$variantId, $variant]) {
				$summed = $experiment['variants'][$variantId]
					?? ['id' => $variantId, 'name' => (string)($variant['name'] ?? $variantId), 'sessions' => 0, 'conversions' => 0];
				$summed['sessions'] += $this->values->int(value: $variant['sessions'] ?? 0);
				$summed['conversions'] += $this->values->int(value: $variant['conversions'] ?? 0);
				$experiment['variants'][$variantId] = $summed;
			}

			$into[$id] = $experiment;
		}

		return $into;
	}
	/**
	 * The summed experiments with each variant's rate and the verdict
	 * re-derived from the summed counts.
	 *
	 * @param array<string, array<string, mixed>> $experiments The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function finishExperiments(array $experiments): array {
		$out = [];
		foreach ($experiments as $experiment) {
			$variants = [];
			foreach ($experiment['variants'] as $variant) {
				$variant['rate'] = round($this->values->ratio(numerator: (float)$variant['conversions'], denominator: (int)$variant['sessions']), 3);
				$variants[] = $variant;
			}

			$experiment['variants'] = $variants;
			$out[] = $experiment + $this->experiments->verdict(variants: $variants);
		}

		return $out;
	}
	/**
	 * Merge funnel rows by id, step by step.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's funnels.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function addFunnels(array $into, mixed $rows): array {
		foreach ($this->keyedRows(rows: $rows, key: 'id') as [$id, $row]) {
			$funnel = $into[$id] ?? ['id' => $id, 'name' => (string)($row['name'] ?? $id), 'steps' => []];
			foreach (array_values((array)($row['steps'] ?? [])) as $index => $step) {
				if (is_array($step) === false) {
					continue;
				}

				$funnel['steps'][$index] = [
					'name' => (string)($step['name'] ?? ($funnel['steps'][$index]['name'] ?? '')),
					'sessions' => (int)($funnel['steps'][$index]['sessions'] ?? 0) + $this->values->int(value: $step['sessions'] ?? 0),
				];
			}

			$into[$id] = $funnel;
		}

		return $into;
	}
	/**
	 * The merged funnels with each step's drop-off re-derived.
	 *
	 * @param array<string, array<string, mixed>> $funnels The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function finishFunnels(array $funnels): array {
		$out = [];
		foreach ($funnels as $funnel) {
			$steps = [];
			$previous = null;
			foreach ($funnel['steps'] as $step) {
				$sessions = (int)$step['sessions'];
				$dropOff = 0.0;
				if ($previous !== null && $previous > 0) {
					$dropOff = round(($previous - $sessions) / $previous, 3);
				}

				$steps[] = ['name' => $step['name'], 'sessions' => $sessions, 'dropOff' => $dropOff];
				$previous = $sessions;
			}

			$out[] = ['id' => $funnel['id'], 'name' => $funnel['name'], 'steps' => $steps];
		}

		return $out;
	}
	/**
	 * Merge form rows by form id, fields by field id.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's forms.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function addForms(array $into, mixed $rows): array {
		foreach ($this->keyedRows(rows: $rows, key: 'formId') as [$id, $row]) {
			$form = $into[$id] ?? ['formId' => $id, 'starts' => 0, 'submits' => 0, 'abandons' => 0, 'fields' => []];
			foreach (['starts', 'submits', 'abandons'] as $key) {
				$form[$key] += $this->values->int(value: $row[$key] ?? 0);
			}

			foreach ($this->keyedRows(rows: (array)($row['fields'] ?? []), key: 'fieldId') as [$fieldId, $field]) {
				$sum = $form['fields'][$fieldId] ?? ['fieldId' => $fieldId, 'ms' => 0, 'members' => 0, 'abandonedHere' => 0];
				$sum['ms'] += $this->values->int(value: $field['avgMs'] ?? 0);
				$sum['members'] += 1;
				$sum['abandonedHere'] += $this->values->int(value: $field['abandonedHere'] ?? 0);
				$form['fields'][$fieldId] = $sum;
			}

			$into[$id] = $form;
		}

		return $into;
	}
	/**
	 * The merged forms with their completion rate and mean field times.
	 *
	 * @param array<string, array<string, mixed>> $forms The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function finishForms(array $forms): array {
		$out = [];
		foreach ($forms as $form) {
			$fields = [];
			foreach ($form['fields'] as $field) {
				$fields[] = [
					'fieldId' => $field['fieldId'],
					'avgMs' => (int)round($this->values->ratio(numerator: (float)$field['ms'], denominator: (int)$field['members']), 0),
					'abandonedHere' => $field['abandonedHere'],
				];
			}

			$starts = (int)$form['starts'];
			$out[] = [
				'formId' => $form['formId'],
				'starts' => $starts,
				'submits' => $form['submits'],
				'abandons' => $form['abandons'],
				'completionRate' => round($this->values->ratio(numerator: (float)$form['submits'], denominator: $starts), 3),
				'fields' => $fields,
			];
		}

		return $out;
	}
	/**
	 * The rows that are arrays with a non-empty key, each paired with that
	 * key as a string, in their order.
	 *
	 * @param mixed  $rows The record's rows.
	 * @param string $key  The field that identifies a row.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}> The [key, row] pairs.
	 */
	private function keyedRows(mixed $rows, string $key): array {
		if (is_array($rows) === false) {
			return [];
		}

		$keyed = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && (string)($row[$key] ?? '') !== '') {
				$keyed[] = [(string)$row[$key], $row];
			}
		}

		return $keyed;
	}
}
