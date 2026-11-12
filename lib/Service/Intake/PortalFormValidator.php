<?php

/**
 * Portaliq Portal Form Validator
 *
 * Validates a submission against the schema of the form it was rendered from,
 * and returns an error per field. It runs BEFORE any create is attempted, so
 * a case app never sees a submission the form itself would have refused, and
 * the citizen is told which answer is missing rather than that something went
 * wrong.
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
 * @spec openspec/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCP\IL10N;

/**
 * Per-field validation of a submission against its form.
 *
 * @spec openspec/specs/portal-intake-form/spec.md
 */
class PortalFormValidator {
	/**
	 * The largest signature image accepted, in bytes.
	 *
	 * @var int
	 */
	public const MAX_SIGNATURE_BYTES = 200000;

	/**
	 * The checks of a single answer against its field.
	 *
	 * @var PortalFieldChecks
	 */
	private readonly PortalFieldChecks $checks;

	/**
	 * Constructor.
	 *
	 * @param IL10N            $l10n        The sentences a refusal is given with.
	 * @param VisibleWhenLocal $visibleWhen Whether a field's condition shows it.
	 * @param DutchFormats     $formats     The Dutch format checks a field may name.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly VisibleWhenLocal $visibleWhen=new VisibleWhenLocal(),
		DutchFormats $formats=new DutchFormats(),
	) {
		$this->checks = new PortalFieldChecks(l10n: $l10n, formats: $formats);
	}//end __construct()

	/**
	 * Validate a submission against the form's fields.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers What the citizen submitted.
	 *
	 * @return array{valid: bool, errors: array<string, string>, answers: array<string, mixed>}
	 *         `answers` carries only the fields the form declares and shows,
	 *         so nothing a client invented, and no answer to a question the
	 *         resident was not shown, reaches a create.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 */
	public function validate(array $fields, array $answers): array {
		// What a condition reads: every answer, less each field found hidden.
		// Taken in declared order, so a question that hangs on a hidden one
		// hides too, while a condition on a later field reads its answer, as
		// the screen's form data holds every answer at once.
		$run = new FormValidationRun(answers: $answers);
		foreach ($fields as $field) {
			$this->check(field: $field, run: $run);
		}

		return ['valid' => ($run->errors === []), 'errors' => $run->errors, 'answers' => $run->accepted];
	}//end validate()

	/**
	 * Check one declared field: skip it when hidden or computed, else check it as a group or a single answer.
	 *
	 * @param mixed             $field The declared field.
	 * @param FormValidationRun $run   The pass so far.
	 *
	 * @return void
	 */
	private function check(mixed $field, FormValidationRun $run): void {
		$name = $this->nameOf(field: $field);
		if ($name === '') {
			return;
		}

		if ($this->isHiddenOrComputed(field: $field, shown: $run->shown) === true) {
			// Not shown, so neither required nor accepted (REQ-ICQ-002); a
			// calculated or decided field is the server's to fill, never
			// the browser's (REQ-FFL-002).
			unset($run->shown[$name]);
			return;
		}

		if ((string)($field['type'] ?? '') === 'group') {
			$this->checkGroup(field: $field, run: $run);
			return;
		}

		$this->checkSingle(field: $field, run: $run);
	}//end check()

	/**
	 * A declared field's name, or '' when it is no field.
	 *
	 * @param mixed $field The declared field.
	 *
	 * @return string
	 */
	private function nameOf(mixed $field): string {
		if (is_array($field) === false) {
			return '';
		}

		return (string)($field['name'] ?? '');
	}//end nameOf()

	/**
	 * Check a repeating group: its errors, and its items when it has any.
	 *
	 * @param array<string, mixed> $field The declared group.
	 * @param FormValidationRun    $run   The pass so far.
	 *
	 * @return void
	 */
	private function checkGroup(array $field, FormValidationRun $run): void {
		$name        = $this->nameOf(field: $field);
		$group       = $this->group(field: $field, name: $name, value: ($run->answers[$name] ?? null));
		$run->errors = array_merge($run->errors, $group['errors']);
		if ($group['items'] !== null) {
			$run->accepted[$name] = $group['items'];
		}
	}//end checkGroup()

	/**
	 * Check a single answer: its error, or its value when it is kept.
	 *
	 * @param array<string, mixed> $field The declared field.
	 * @param FormValidationRun    $run   The pass so far.
	 *
	 * @return void
	 */
	private function checkSingle(array $field, FormValidationRun $run): void {
		$name   = $this->nameOf(field: $field);
		$single = $this->single(field: $field, value: ($run->answers[$name] ?? null));
		if ($single['error'] !== null) {
			$run->errors[$name] = $single['error'];
			return;
		}

		if ($single['kept'] === true) {
			$run->accepted[$name] = $single['value'];
		}
	}//end checkSingle()

	/**
	 * Whether a field is not shown by its condition, or is one the server fills.
	 *
	 * A calculated or decided field is the server's to fill, never the
	 * browser's (form-flow-repeating-groups-calculations-and-decisions REQ-FFL-002).
	 *
	 * @param array<string, mixed> $field The field.
	 * @param array<string, mixed> $shown The answers a condition reads.
	 *
	 * @return bool
	 */
	private function isHiddenOrComputed(array $field, array $shown): bool {
		if ($this->visibleWhen->isVisible(condition: ($field['visibleWhen'] ?? null), answers: $shown) === false) {
			return true;
		}

		return isset($field['calculate']) === true || ($field['computed'] ?? false) === true;
	}//end isHiddenOrComputed()

	/**
	 * Validate a plain field's answer.
	 *
	 * @param array<string, mixed> $field The field.
	 * @param mixed                $value What the browser sent for it.
	 *
	 * @return array{error: string|null, kept: bool, value: mixed} The refusal, or whether the answer is kept and its stored value.
	 */
	private function single(array $field, mixed $value): array {
		if ($this->wasAnswered(value: $value) === false) {
			$error = null;
			if (($field['required'] ?? false) === true) {
				$error = $this->l10n->t('This answer is required.');
			}

			return ['error' => $error, 'kept' => false, 'value' => null];
		}

		$error = $this->checks->checkValue(field: $field, value: $value);
		if ($error !== null) {
			return ['error' => $error, 'kept' => false, 'value' => null];
		}

		return ['error' => null, 'kept' => true, 'value' => $this->checks->stored(field: $field, value: $value)];
	}//end single()

	/**
	 * Validate a repeating group: a list whose count fits `repeat.min` and
	 * `repeat.max` and whose items each fit the group's sub-fields.
	 *
	 * An item error is keyed `name[index].field` (the first item is 0), so the
	 * summary can name the item and the field.
	 *
	 * @param array<string, mixed> $field The group's declaration.
	 * @param string $name The group's name.
	 * @param mixed $value What the browser sent for it.
	 *
	 * @return array{errors: array<string, string>, items: array<int, array<string, mixed>>|null}
	 *         The errors, and the accepted items, or null when none are kept.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t01
	 */
	private function group(array $field, string $name, mixed $value): array {
		$repeat = (array)($field['repeat'] ?? []);
		$min    = (int)($repeat['min'] ?? 0);
		if (($field['required'] ?? false) === true) {
			$min = max($min, 1);
		}

		$max    = (int)($repeat['max'] ?? 0);
		$items  = [];
		if (is_array($value) === true && array_is_list($value) === true) {
			$items = $value;
		} elseif ($this->wasAnswered(value: $value) === true) {
			return ['errors' => [$name => $this->l10n->t('This answer must be a list.')], 'items' => null];
		}

		if (count($items) < $min) {
			return ['errors' => [$name => $this->l10n->t('Add at least %s.', [(string)$min])], 'items' => null];
		}

		if ($max > 0 && count($items) > $max) {
			return ['errors' => [$name => $this->l10n->t('You can add at most %s.', [(string)$max])], 'items' => null];
		}

		return $this->groupItems(field: $field, name: $name, items: $items);
	}//end group()

	/**
	 * Validate the items of a repeating group against its sub-fields.
	 *
	 * @param array<string, mixed> $field The group's declaration.
	 * @param string               $name  The group's name.
	 * @param array<int, mixed>    $items The items the browser sent.
	 *
	 * @return array{errors: array<string, string>, items: array<int, array<string, mixed>>|null}
	 */
	private function groupItems(array $field, string $name, array $items): array {
		$errors   = [];
		$accepted = [];
		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				$errors[$name.'['.$index.']'] = $this->l10n->t('This answer is required.');
				continue;
			}

			$checked = $this->validate(fields: (array)($field['fields'] ?? []), answers: $item);
			foreach ($checked['errors'] as $key => $message) {
				$errors[$name.'['.$index.'].'.$key] = $message;
			}

			$accepted[] = $checked['answers'];
		}

		if ($accepted === []) {
			return ['errors' => $errors, 'items' => null];
		}

		return ['errors' => $errors, 'items' => $accepted];
	}//end groupItems()

	/**
	 * Whether the visitor answered this field at all.
	 *
	 * An empty array, an empty string and whitespace are all "not answered",
	 * so a required field cannot be satisfied with a space.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return bool
	 */
	private function wasAnswered(mixed $value): bool {
		if (is_array($value) === true) {
			return ($value !== []);
		}

		if ($value === null) {
			return false;
		}

		return (trim((string)$value) !== '');
	}//end wasAnswered()

}//end class
