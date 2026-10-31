<?php

/**
 * Portaliq Form Confirmation Summary (form-statements-intro-and-confirmation-mail)
 *
 * @category Intake
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
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * The visible answers of a submission as label and value, for the mail.
 *
 * Nothing sensitive leaves with it: no file, no signature, no BSN, no
 * family reference, and no answer to a question that is not a plain value.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
 */
class FormConfirmationSummary {
	/**
	 * Field types whose answer never goes in a mail.
	 *
	 * @var string[]
	 */
	private const WITHHELD_TYPES = ['file', 'signature', 'familyMembers', 'password'];

	/**
	 * The answers worth repeating back, in the form's order.
	 *
	 * @param array<int, array<string, mixed>> $fields  The form's fields.
	 * @param array<string, mixed>             $answers The accepted answers.
	 *
	 * @return array<int, array{label: string, value: string}>
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function build(array $fields, array $answers): array {
		$lines = [];
		foreach ($fields as $field) {
			$name = (string)($field['name'] ?? '');
			if ($name === '' || array_key_exists($name, $answers) === false || $this->withheld(field: $field) === true) {
				continue;
			}

			$value = $this->text(field: $field, value: $answers[$name]);
			if ($value === '') {
				continue;
			}

			$label = trim((string)($field['label'] ?? ''));
			if ($label === '') {
				$label = $name;
			}

			$lines[] = ['label' => $label, 'value' => $value];
		}

		return $lines;
	}//end build()

	/**
	 * Whether a field's answer stays out of the mail.
	 *
	 * @param array<string, mixed> $field The field.
	 *
	 * @return bool
	 */
	private function withheld(array $field): bool {
		return in_array((string)($field['type'] ?? ''), self::WITHHELD_TYPES, true) === true
			|| (string)($field['format'] ?? '') === 'bsn'
			|| ($field['sensitive'] ?? false) === true;
	}//end withheld()

	/**
	 * One answer as text: an option's label, an address on one line, or the value.
	 *
	 * @param array<string, mixed> $field The field.
	 * @param mixed                $value The answer.
	 *
	 * @return string
	 */
	private function text(array $field, mixed $value): string {
		if (is_array($value) === true) {
			if (($field['type'] ?? '') !== 'addressNL') {
				return '';
			}

			$house  = trim((string)($value['number'] ?? '') . (string)($value['letter'] ?? '') . (string)($value['addition'] ?? ''));
			$street = trim((string)($value['street'] ?? '') . ' ' . $house);
			$town   = trim((string)($value['postcode'] ?? '') . ' ' . (string)($value['town'] ?? ''));

			return trim($street . ', ' . $town, ', ');
		}

		if (is_scalar($value) === false) {
			return '';
		}

		$text = trim((string)$value);
		foreach ((array)($field['options'] ?? []) as $option) {
			if (is_array($option) === true && (string)($option['value'] ?? '') === $text && trim((string)($option['label'] ?? '')) !== '') {
				return trim((string)$option['label']);
			}
		}

		return $text;
	}//end text()
}//end class
