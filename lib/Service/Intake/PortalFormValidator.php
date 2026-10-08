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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCP\IL10N;

/**
 * Per-field validation of a submission against its form.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFormValidator {
	/**
	 * Constructor.
	 *
	 * @param IL10N            $l10n        The sentences a refusal is given with.
	 * @param VisibleWhenLocal $visibleWhen Whether a field's condition shows it.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly VisibleWhenLocal $visibleWhen=new VisibleWhenLocal(),
	) {
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
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 */
	public function validate(array $fields, array $answers): array {
		$errors = [];
		$accepted = [];
		// What a condition reads: every answer, less each field found hidden.
		// Taken in declared order, so a question that hangs on a hidden one
		// hides too, while a condition on a later field reads its answer, as
		// the screen's form data holds every answer at once.
		$shown = $answers;
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '') {
				continue;
			}

			if ($this->visibleWhen->isVisible(condition: ($field['visibleWhen'] ?? null), answers: $shown) === false) {
				// Not shown, so neither required nor accepted (REQ-ICQ-002).
				unset($shown[$name]);
				continue;
			}

			$value = ($answers[$name] ?? null);
			if ($this->wasAnswered(value: $value) === false) {
				if (($field['required'] ?? false) === true) {
					$errors[$name] = $this->l10n->t('This answer is required.');
				}

				continue;
			}

			$error = $this->checkValue(field: $field, value: $value);
			if ($error !== null) {
				$errors[$name] = $error;
				continue;
			}

			$accepted[$name] = $value;
		}//end foreach

		return ['valid' => ($errors === []), 'errors' => $errors, 'answers' => $accepted];
	}//end validate()

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

	/**
	 * The error one value carries, or null when it is fine.
	 *
	 * One rule per helper, asked in declaration order, so the visitor reads
	 * the first thing wrong with their answer rather than the last.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 */
	private function checkValue(array $field, mixed $value): ?string {
		$error = $this->typeError(type: (string)($field['type'] ?? 'string'), value: $value);
		if ($error !== null) {
			return $error;
		}

		$error = $this->patternError(field: $field, value: $value);
		if ($error !== null) {
			return $error;
		}

		$error = $this->optionsError(field: $field, value: $value);
		if ($error !== null) {
			return $error;
		}

		return $this->lengthError(field: $field, value: $value);
	}//end checkValue()

	/**
	 * Whether the value is of the type the field declares.
	 *
	 * @param string $type The declared type.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 */
	private function typeError(string $type, mixed $value): ?string {
		if ($type === 'number' && is_numeric($value) === false) {
			return $this->l10n->t('This answer must be a number.');
		}

		if ($type === 'email' && filter_var((string)$value, FILTER_VALIDATE_EMAIL) === false) {
			return $this->l10n->t('This does not look like an email address.');
		}

		return null;
	}//end typeError()

	/**
	 * Whether the value matches the pattern the field declares.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 */
	private function patternError(array $field, mixed $value): ?string {
		$pattern = (string)($field['pattern'] ?? '');
		if ($pattern === '' || is_string($value) === false) {
			return null;
		}

		if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $value) !== 1) {
			return $this->l10n->t('This answer is not in the expected format.');
		}

		return null;
	}//end patternError()

	/**
	 * Whether the value is one of the options the field offers.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 */
	private function optionsError(array $field, mixed $value): ?string {
		$options = ($field['options'] ?? null);
		if (is_array($options) === false || $options === []) {
			return null;
		}

		if (in_array($value, $options, true) === false) {
			return $this->l10n->t('Choose one of the options offered.');
		}

		return null;
	}//end optionsError()

	/**
	 * Whether the value fits the length the field allows.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 */
	private function lengthError(array $field, mixed $value): ?string {
		$maxLength = (int)($field['maxLength'] ?? 0);
		if ($maxLength <= 0 || is_string($value) === false) {
			return null;
		}

		if (mb_strlen($value) > $maxLength) {
			return $this->l10n->t('This answer is too long.');
		}

		return null;
	}//end lengthError()
}//end class
