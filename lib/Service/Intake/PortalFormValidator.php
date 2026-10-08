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
	 * The largest signature image accepted, in bytes.
	 *
	 * @var int
	 */
	public const MAX_SIGNATURE_BYTES = 200000;

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
		private readonly DutchFormats $formats=new DutchFormats(),
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

			// A calculated or decided field is the server's to fill, never the browser's
			// (form-flow-repeating-groups-calculations-and-decisions REQ-FFL-002).
			if (isset($field['calculate']) === true || ($field['computed'] ?? false) === true) {
				unset($shown[$name]);
				continue;
			}

			if ((string)($field['type'] ?? '') === 'group') {
				$group = $this->group(field: $field, name: $name, value: ($answers[$name] ?? null));
				$errors = array_merge($errors, $group['errors']);
				if ($group['items'] !== null) {
					$accepted[$name] = $group['items'];
				}

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

			$accepted[$name] = $this->stored(field: $field, value: $value);
		}//end foreach

		return ['valid' => ($errors === []), 'errors' => $errors, 'answers' => $accepted];
	}//end validate()

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
	}//end group()

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

		$error = $this->formatError(field: $field, value: $value);
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

		if ($type === 'familyMembers') {
			return $this->familyShapeError(value: $value);
		}

		if ($type === 'addressNL') {
			return $this->addressError(value: $value);
		}

		if ($type === 'signature') {
			return $this->signatureError(value: $value);
		}

		if ($type === 'email' && filter_var((string)$value, FILTER_VALIDATE_EMAIL) === false) {
			return $this->l10n->t('This does not look like an email address.');
		}

		return null;
	}//end typeError()

	/**
	 * Whether a signature is a PNG image of at most 200 kB, sent as a data address.
	 *
	 * @param mixed $value The submitted signature.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
	 */
	private function signatureError(mixed $value): ?string {
		$prefix = 'data:image/png;base64,';
		if (is_string($value) === false || str_starts_with($value, $prefix) === false) {
			return $this->l10n->t('The signature must be an image.');
		}

		$bytes = base64_decode(substr($value, strlen($prefix)), true);
		if ($bytes === false || str_starts_with($bytes, "\x89PNG\r\n\x1a\n") === false) {
			return $this->l10n->t('The signature must be an image.');
		}

		if (strlen($bytes) > self::MAX_SIGNATURE_BYTES) {
			return $this->l10n->t('The signature is too large. Draw it again, smaller.');
		}

		return null;
	}//end signatureError()

	/**
	 * Whether a family answer is a list of references. Whether they ARE the
	 * resident's family is the controller's check, against the BRP.
	 *
	 * @param mixed $value The submitted list.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	private function familyShapeError(mixed $value): ?string {
		if (is_array($value) === false || array_is_list($value) === false) {
			return $this->l10n->t('Choose the people from the list we found.');
		}

		foreach ($value as $ref) {
			if (is_string($ref) === false || preg_match('/^(partner|child)-[a-f0-9]{20}$/', $ref) !== 1) {
				return $this->l10n->t('Choose the people from the list we found.');
			}
		}

		return null;
	}//end familyShapeError()

	/**
	 * Whether an address block holds a real postcode, a house number, a street and a town.
	 *
	 * @param mixed $value The submitted block.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
	 */
	private function addressError(mixed $value): ?string {
		$block = [];
		if (is_array($value) === true) {
			$block = $value;
		}

		$complete = $this->formats->normalise(format: 'postcode', value: (string)($block['postcode'] ?? '')) !== null
			&& preg_match('/^[1-9]\d{0,4}$/', (string)($block['number'] ?? '')) === 1
			&& trim((string)($block['street'] ?? '')) !== ''
			&& trim((string)($block['town'] ?? '')) !== '';
		if ($complete === false) {
			return $this->l10n->t('Fill in the postcode, the house number, the street and the town.');
		}

		return null;
	}//end addressError()

	/**
	 * Whether the value fits the Dutch format the field names (`format`).
	 *
	 * A name this server does not know checks nothing; a known one is checked
	 * here whatever the screen did, because the screen can be bypassed.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
	 */
	private function formatError(array $field, mixed $value): ?string {
		$format = (string)($field['format'] ?? '');
		if ($this->formats->knows(format: $format) === false) {
			return null;
		}

		if (is_string($value) === true && $this->formats->normalise(format: $format, value: $value) !== null) {
			return null;
		}

		$messages = [
			'bsn' => $this->l10n->t('This citizen service number does not look right. Check the digits.'),
			'iban' => $this->l10n->t('This IBAN does not look right. Check the digits.'),
			'nl-licence-plate' => $this->l10n->t('This licence plate does not look right. You may fill it in with or without dashes.'),
			'phone-nl' => $this->l10n->t('This is not a Dutch phone number. Fill it in as 06 12345678 or +31 6 12345678.'),
			'phone-international' => $this->l10n->t('This is not an international phone number. Start with + and the country code.'),
			'postcode' => $this->l10n->t('This postcode does not look right. Fill it in as 1234 AB.'),
			'kvk' => $this->l10n->t('A KvK number has 8 digits.'),
			'kvk-branch' => $this->l10n->t('A branch number has 12 digits.'),
		];

		return $messages[$format];
	}//end formatError()

	/**
	 * The value as it is stored: normalised when the field names a format.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The accepted value.
	 *
	 * @return mixed
	 */
	private function stored(array $field, mixed $value): mixed {
		$format = (string)($field['format'] ?? '');
		if ($this->formats->knows(format: $format) === true && is_string($value) === true) {
			return ($this->formats->normalise(format: $format, value: $value) ?? $value);
		}

		return $value;
	}//end stored()

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
		if (($field['referenceListEmpty'] ?? false) === true) {
			// A list that could not be read offers nothing, so nothing is accepted.
			return $this->l10n->t('Choose one of the options offered.');
		}

		$options = ($field['options'] ?? null);
		if (is_array($options) === false || $options === []) {
			return null;
		}

		$values = array_map(
			static function ($option) {
				if (is_array($option) === true && array_key_exists('value', $option) === true) {
					return $option['value'];
				}

				return $option;
			},
			$options
		);
		if (in_array($value, $values, true) === false) {
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
