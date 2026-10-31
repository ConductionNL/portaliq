<?php

/**
 * Portaliq Plan Field Changes (shared-plans-with-a-caseworker)
 *
 * Checks one requested change to a plan and says what to write.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

/**
 * One requested change to a plan: who may make it and what it writes.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PlanFieldChanges {
	/**
	 * Constructor.
	 *
	 * @param PlanRules $rules The plan rules, for the end date.
	 */
	public function __construct(
		private readonly PlanRules $rules = new PlanRules(),
	) {
	}//end __construct()

	/**
	 * Check one requested change and say what to write.
	 *
	 * @param array<string, mixed> $plan    The plan row.
	 * @param string               $editor  The editor's subject reference, for the note.
	 * @param bool                 $isOwner Whether the viewer made the plan.
	 * @param string               $field   The field.
	 * @param mixed                $value   The new value.
	 * @param string               $today   Today, `Y-m-d`.
	 *
	 * @return array{status: string, data: array<string, mixed>}
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function change(array $plan, string $editor, bool $isOwner, string $field, mixed $value, string $today): array {
		if (in_array($field, ['title', 'endDate', 'status'], true) === true && $isOwner === false) {
			return ['status' => PortalPlanService::FORBIDDEN, 'data' => []];
		}

		$data = match ($field) {
			'goal', 'goalDetail' => $this->goal(field: $field, value: $value),
			'title' => $this->title(value: $value),
			'note' => $this->note(value: $value, editor: $editor),
			'endDate' => $this->endDate(plan: $plan, value: $value, today: $today),
			'status' => $this->done(value: $value),
			default => null,
		};
		if ($data === null) {
			return ['status' => PortalPlanService::INVALID, 'data' => []];
		}

		return ['status' => PortalPlanService::OK, 'data' => $data];
	}//end change()

	/**
	 * The goal or its explanation, up to 2000 characters.
	 *
	 * @param string $field The field.
	 * @param mixed  $value The new value.
	 *
	 * @return array<string, string>|null Null when the value is not acceptable.
	 */
	private function goal(string $field, mixed $value): ?array {
		if (is_string($value) === false || mb_strlen($value) > 2000) {
			return null;
		}

		return [$field => trim($value)];
	}//end goal()

	/**
	 * The title, 1 to 200 characters.
	 *
	 * @param mixed $value The new value.
	 *
	 * @return array<string, string>|null Null when the value is not acceptable.
	 */
	private function title(mixed $value): ?array {
		if (is_string($value) === false || trim($value) === '' || mb_strlen($value) > 200) {
			return null;
		}

		return ['title' => trim($value)];
	}//end title()

	/**
	 * The note, up to 5000 characters, with who wrote it and when.
	 *
	 * @param mixed  $value  The new value.
	 * @param string $editor The editor's subject reference.
	 *
	 * @return array<string, array<string, string>>|null Null when the value is not acceptable.
	 */
	private function note(mixed $value, string $editor): ?array {
		if (is_string($value) === false || mb_strlen($value) > 5000) {
			return null;
		}

		return ['note' => ['text' => trim($value), 'editedBy' => $editor, 'editedAt' => gmdate(DATE_ATOM)]];
	}//end note()

	/**
	 * The end date, a day; a new one earns a new reminder.
	 *
	 * @param array<string, mixed> $plan  The plan row.
	 * @param mixed                $value The new value.
	 * @param string               $today Today, `Y-m-d`.
	 *
	 * @return array<string, mixed>|null Null when the value is not acceptable.
	 */
	private function endDate(array $plan, mixed $value, string $today): ?array {
		if (is_string($value) === false || $this->rules->daysLeft(endDate: $value, today: $today) === null || strlen($value) !== 10) {
			return null;
		}

		$data = ['endDate' => $value];
		if ($value !== substr((string)($plan['endDate'] ?? ''), 0, 10)) {
			// A new end date earns a new reminder.
			$data['endReminderSentAt'] = null;
		}

		return $data;
	}//end endDate()

	/**
	 * Marking the plan done: the only status change there is.
	 *
	 * @param mixed $value The new value.
	 *
	 * @return array<string, string>|null Null when the value is not `done`.
	 */
	private function done(mixed $value): ?array {
		if ($value !== 'done') {
			return null;
		}

		return ['status' => 'done', 'doneAt' => gmdate(DATE_ATOM)];
	}//end done()
}//end class
