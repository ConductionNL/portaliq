<?php

/**
 * Portaliq Message Contacts Keys (site-messages-per-record)
 *
 * A collection MAY say who a resident may write to about each of its rows:
 * `contacts: {provider, recordLabelFields?, composeLabel?, composeHint?}`
 * names a method on the contributing app's provider that answers, for one
 * row the resident owns, the people of the organisation they may start a
 * conversation with (`[{staffRef, name, role?}]`). `recordLabelFields` are
 * projected fields that name the row in the picker and on a conversation
 * ("Vera", "groep 7"); `composeLabel` and `composeHint` are the heading and
 * help text of the form ("Een bericht aan de leerkracht").
 *
 * SECURITY: the provider name passes the same rule as a timeline provider,
 * so a manifest can never make portaliq call one of the contract's own
 * methods. A provider answer is held to a fixed shape; an entry that does
 * not fit is dropped.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a collection's `contacts` key, and a provider's answer, in shape.
 *
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
 */
class MessageContactsKeys {
	/**
	 * The longest compose heading, hint, name or role.
	 */
	private const MAX_TEXT = 200;

	/**
	 * The most label fields one record name joins.
	 */
	private const MAX_LABEL_FIELDS = 3;

	/**
	 * The most contacts one row answers.
	 */
	private const MAX_CONTACTS = 30;

	/**
	 * Keep `contacts` when its provider is callable by name; drop a label
	 * field the collection does not project, and the whole key when the
	 * provider does not fit.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('contacts', $collection) === false) {
			return $collection;
		}

		$declared = $collection['contacts'];
		unset($collection['contacts']);
		if (is_array($declared) === false || (new TimelineProviderMethod())->accepts(name: ($declared['provider'] ?? null)) === false) {
			return $collection;
		}

		$out       = ['provider' => $declared['provider']];
		$projected = (array)($collection['fields'] ?? []);
		$fields    = array_values(
			array_filter(
				(array)($declared['recordLabelFields'] ?? []),
				static fn ($field): bool => is_string($field) === true && in_array($field, $projected, true) === true
			)
		);
		if ($fields !== []) {
			$out['recordLabelFields'] = array_slice($fields, 0, self::MAX_LABEL_FIELDS);
		}

		foreach (['composeLabel', 'composeHint'] as $key) {
			$text = $this->text(value: ($declared[$key] ?? null));
			if ($text !== null) {
				$out[$key] = $text;
			}
		}

		$collection['contacts'] = $out;
		return $collection;
	}//end normalise()

	/**
	 * A provider's answer for one row, held to `{staffRef, name, role}`: an
	 * entry without a person or a name is dropped, never passed on as it came.
	 *
	 * @param array<int|string, mixed> $entries The provider's answer.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-name-who-a-resident-may-write-to-about-each-row
	 */
	public function contacts(array $entries): array {
		$out  = [];
		$seen = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$staffRef = $this->text(value: ($entry['staffRef'] ?? null));
			$name     = $this->text(value: ($entry['name'] ?? null));
			if ($staffRef === null || $name === null || isset($seen[$staffRef]) === true) {
				continue;
			}

			$seen[$staffRef] = true;
			$out[]           = ['staffRef' => $staffRef, 'name' => $name, 'role' => ($this->text(value: ($entry['role'] ?? null)) ?? '')];
			if (count($out) >= self::MAX_CONTACTS) {
				break;
			}
		}

		return $out;
	}//end contacts()

	/**
	 * A trimmed, non-empty string of at most MAX_TEXT characters, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$value = trim($value);
		if ($value === '' || mb_strlen($value) > self::MAX_TEXT) {
			return null;
		}

		return $value;
	}//end text()
}//end class
