<?php

/**
 * Portaliq Field Checks (portal-intake-form-as-an-object)
 *
 * Checks one answer against its field.
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
 * The type, format, pattern, option and length checks of a single answer.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFieldChecks {
	/**
	 * The pattern, option and length limits of a field.
	 *
	 * @var PortalFieldLimits
	 */
	private readonly PortalFieldLimits $limits;

	/**
	 * Constructor.
	 *
	 * @param IL10N        $l10n    The sentences a refusal is given with.
	 * @param DutchFormats $formats The Dutch format checks a field may name.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly DutchFormats $formats,
	) {
		$this->limits = new PortalFieldLimits(l10n: $l10n);
	}//end __construct()

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
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function checkValue(array $field, mixed $value): ?string {
		$error = $this->typeError(type: (string)($field['type'] ?? 'string'), value: $value);
		if ($error !== null) {
			return $error;
		}

		$error = $this->formatError(field: $field, value: $value);
		if ($error !== null) {
			return $error;
		}

		$error = $this->limits->patternError(field: $field, value: $value);
		if ($error !== null) {
			return $error;
		}

		$error = $this->limits->optionsError(field: $field, value: $value);
		if ($error !== null) {
			return $error;
		}

		return $this->limits->lengthError(field: $field, value: $value);
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

		if (strlen($bytes) > PortalFormValidator::MAX_SIGNATURE_BYTES) {
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
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function stored(array $field, mixed $value): mixed {
		$format = (string)($field['format'] ?? '');
		if ($this->formats->knows(format: $format) === true && is_string($value) === true) {
			return ($this->formats->normalise(format: $format, value: $value) ?? $value);
		}

		return $value;
	}//end stored()

}//end class
