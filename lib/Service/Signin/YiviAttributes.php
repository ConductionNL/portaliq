<?php

/**
 * Portaliq Yivi Attributes
 *
 * The attributes a resident disclosed in Yivi, and the form fields they fill.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Signin
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-resident-can-sign-in-with-yivi-and-share-only-what-the-form-asks-req-rif-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Signin;

/**
 * Reads the disclosed attributes off a broker envelope or a session, and maps
 * a form's `yiviAttributes` onto its fields.
 *
 * An attribute is kept only when its id has the Yivi shape (dot-separated
 * segments, as in `pbdf.gemeente.personalData.fullname`) and its value is a
 * non-empty string of bounded length. Anything else the envelope carries is
 * dropped, so the session holds the disclosed attributes and nothing more.
 *
 * Pure: no container, no config reads.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-resident-can-sign-in-with-yivi-and-share-only-what-the-form-asks-req-rif-004
 */
class YiviAttributes {

	/**
	 * The provider, and the identity type of the account it signs in.
	 */
	public const PROVIDER = 'yivi';

	/**
	 * The most attributes one sign-in may carry.
	 */
	public const MAX_ATTRIBUTES = 32;

	/**
	 * The longest attribute value kept, in bytes.
	 */
	public const MAX_VALUE_LENGTH = 1024;

	/**
	 * A Yivi attribute id: two or more dot-separated segments.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}(\.[A-Za-z0-9_-]{1,64}){1,7}$/';


	/**
	 * The disclosed attributes in a claim: attribute id to value.
	 *
	 * @param mixed $claim The `attributes` claim of an envelope or a session.
	 *
	 * @return array<string, string> Valid ids with non-empty values, in claim order.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T05
	 */
	public function disclosed(mixed $claim): array {
		if (is_array($claim) === false) {
			return [];
		}

		$attributes = [];
		foreach ($claim as $id => $value) {
			if (count($attributes) >= self::MAX_ATTRIBUTES) {
				break;
			}

			if ($this->isId(id: $id) === false || is_string($value) === false) {
				continue;
			}

			$value = trim($value);
			if ($value === '' || strlen($value) > self::MAX_VALUE_LENGTH) {
				continue;
			}

			$attributes[(string)$id] = $value;
		}

		return $attributes;
	}//end disclosed()


	/**
	 * The attributes a form asks for, each with the field it fills.
	 *
	 * An entry without a valid attribute id or a field name is skipped, and
	 * an attribute named twice keeps its first field.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return list<array{attribute: string, field: string}>
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T05
	 */
	public function requested(array $binding): array {
		$declared = ($binding['yiviAttributes'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$requested = [];
		foreach ($declared as $entry) {
			$attribute = ($entry['attribute'] ?? null);
			$field = ($entry['field'] ?? null);
			if ($this->isId(id: $attribute) === false || is_string($field) === false || trim($field) === '') {
				continue;
			}

			if (isset($requested[$attribute]) === false) {
				$requested[$attribute] = ['attribute' => $attribute, 'field' => trim($field)];
			}
		}

		return array_values($requested);
	}//end requested()


	/**
	 * The prefill of a form from the disclosed attributes: field name to value.
	 *
	 * Only attributes the form asks for, only into fields the form has.
	 *
	 * @param array<string, mixed>             $binding   The form binding.
	 * @param array<string, string>            $disclosed The session's disclosed attributes.
	 * @param array<int, array<string, mixed>> $fields    The form's fields.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T05
	 */
	public function prefill(array $binding, array $disclosed, array $fields): array {
		$names = [];
		foreach (array_filter($fields, 'is_array') as $field) {
			$names[(string)($field['name'] ?? '')] = true;
		}

		$prefill = [];
		foreach ($this->requested(binding: $binding) as $request) {
			$value = ($disclosed[$request['attribute']] ?? null);
			if (is_string($value) === true && $value !== '' && isset($names[$request['field']]) === true
				&& isset($prefill[$request['field']]) === false
			) {
				$prefill[$request['field']] = $value;
			}
		}

		return $prefill;
	}//end prefill()


	/**
	 * Whether a value is a Yivi attribute id.
	 *
	 * @param mixed $id The value.
	 *
	 * @return bool
	 */
	private function isId(mixed $id): bool {
		return is_string($id) === true && preg_match(self::ID_PATTERN, $id) === 1;
	}//end isId()
}//end class
