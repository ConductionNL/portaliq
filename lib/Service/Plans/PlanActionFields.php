<?php

/**
 * Portaliq Plan Action Fields (shared-plans-with-a-caseworker)
 *
 * The fields of a plan action a participant may write.
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
 * Holds what was sent for an action of a plan to the fields a participant may write.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PlanActionFields {
	/**
	 * The fields, after the title, in the order they are kept.
	 *
	 * @var string[]
	 */
	private const FIELDS = ['status', 'kind', 'description', 'endDate', 'assignee'];

	/**
	 * The fields of an action a participant may write, or null when one is wrong.
	 *
	 * @param array<string, mixed> $data         What was sent.
	 * @param array<int, string>   $members      The owner and the participants, who may be assigned.
	 * @param bool                 $requireTitle Whether a title is needed (a new action).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function clean(array $data, array $members, bool $requireTitle): ?array {
		$clean = [];
		if (array_key_exists('title', $data) === true || $requireTitle === true) {
			$title = $this->text(value: ($data['title'] ?? null));
			if ($title === '' || mb_strlen($title) > 200) {
				return null;
			}

			$clean['title'] = $title;
		}

		foreach (self::FIELDS as $field) {
			if (array_key_exists($field, $data) === false) {
				continue;
			}

			$value = $this->fieldValue(field: $field, value: $data[$field], members: $members);
			if ($value === null) {
				return null;
			}

			$clean[$field] = $value;
		}

		return $clean;
	}//end clean()

	/**
	 * The kept value of one field, or null when it is wrong.
	 *
	 * @param string             $field   The field.
	 * @param mixed              $value   What was sent.
	 * @param array<int, string> $members Who may be assigned.
	 *
	 * @return mixed The kept value, or null when it is wrong.
	 */
	private function fieldValue(string $field, mixed $value, array $members): mixed {
		if ($field === 'status') {
			return $this->oneOf(value: $value, allowed: PortalPlanService::STATUSES);
		}

		if ($field === 'kind') {
			return $this->oneOf(value: $value, allowed: ['once', 'recurring']);
		}

		if ($field === 'description') {
			return $this->description(value: $value);
		}

		if ($field === 'endDate') {
			return $this->day(value: $value);
		}

		return $this->oneOf(value: $value, allowed: $members);
	}//end fieldValue()

	/**
	 * The value when it is one of the allowed values, else null.
	 *
	 * @param mixed             $value   What was sent.
	 * @param array<int, mixed> $allowed The allowed values.
	 *
	 * @return mixed
	 */
	private function oneOf(mixed $value, array $allowed): mixed {
		if (is_string($value) === true && in_array($value, $allowed, true) === true) {
			return $value;
		}

		return null;
	}//end oneOf()

	/**
	 * The description, trimmed, up to 2000 characters, else null.
	 *
	 * @param mixed $value What was sent.
	 *
	 * @return string|null
	 */
	private function description(mixed $value): ?string {
		if (is_string($value) === true && mb_strlen($value) <= 2000) {
			return trim($value);
		}

		return null;
	}//end description()

	/**
	 * The day when it is written `Y-m-d`, else null.
	 *
	 * @param mixed $value What was sent.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value;
		}

		return null;
	}//end day()

	/**
	 * A text, trimmed, or '' when the value is not text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		return '';
	}//end text()
}//end class
