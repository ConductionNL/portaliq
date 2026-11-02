<?php

/**
 * Portaliq Field Limits (portal-intake-form-as-an-object)
 *
 * The pattern, option and length limits of one answer.
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
 * Holds one answer to the limits its field declares.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFieldLimits {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The sentences a refusal is given with.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Whether the value matches the pattern the field declares.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param mixed $value The submitted value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function patternError(array $field, mixed $value): ?string {
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
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function optionsError(array $field, mixed $value): ?string {
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
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function lengthError(array $field, mixed $value): ?string {
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
