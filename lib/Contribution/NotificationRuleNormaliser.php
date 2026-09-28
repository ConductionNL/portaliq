<?php

/**
 * Portaliq Notification Rule Normaliser (inbox-notifications-and-preferences)
 *
 * A contribution's `notifications` list holds plain rule keys
 * (`message.created`, `status.changed`) and, since this change, change rule
 * objects: a case app says which field of which of its collections a resident
 * should hear about when it changes.
 *
 *     {"ruleKey": "case.updated", "collection": "mijnZaken",
 *      "on": {"field": "status", "operator": "changed"}, "titleField": "identifier"}
 *
 * A rule is kept only when the listener can act on it safely. Its collection
 * must be one of the same contribution's collections and use default subject
 * scoping, so the record itself says whose it is (a `scopeClaim` or `via`
 * collection does not). Its field must be one the collection projects to the
 * resident, so a rule can never announce a change to a field the resident may
 * not see. Anything else is dropped, and the reason is returned so the caller
 * can log it.
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
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps the plain rule keys and the change rules the listener can act on.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */
class NotificationRuleNormaliser {

	/**
	 * The only operator a change rule may use.
	 *
	 * @var string
	 */
	public const OPERATOR_CHANGED = 'changed';

	/**
	 * Normalise a contribution's `notifications` list.
	 *
	 * @param mixed                            $notifications The declared list.
	 * @param array<int, array<string, mixed>> $collections   The contribution's normalised collections.
	 *
	 * @return array{kept: array<int, string|array<string, mixed>>, dropped: array<int, string>}
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
	 */
	public function normalise(mixed $notifications, array $collections): array {
		$kept = [];
		$dropped = [];
		if (is_array($notifications) === false) {
			return ['kept' => $kept, 'dropped' => $dropped];
		}

		foreach ($notifications as $entry) {
			if (is_string($entry) === true) {
				if ($entry !== '') {
					$kept[] = $entry;
				}

				continue;
			}

			if (is_array($entry) === false) {
				continue;
			}

			$reason = $this->refusal(rule: $entry, collections: $collections);
			if ($reason !== null) {
				$dropped[] = $reason;
				continue;
			}

			$kept[] = $this->shape(rule: $entry, collection: $this->collection(id: (string)$entry['collection'], collections: $collections));
		}//end foreach

		return ['kept' => $kept, 'dropped' => $dropped];
	}//end normalise()

	/**
	 * Why a rule cannot be kept, or null when it can.
	 *
	 * @param array<string, mixed>             $rule        The rule.
	 * @param array<int, array<string, mixed>> $collections The collections.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(array $rule, array $collections): ?string {
		$ruleKey = ($rule['ruleKey'] ?? null);
		$collectionId = ($rule['collection'] ?? null);
		if (is_string($ruleKey) === false || $ruleKey === '' || is_string($collectionId) === false || $collectionId === '') {
			return 'a change rule needs a ruleKey and a collection';
		}

		$label = sprintf('rule %s on %s', $ruleKey, $collectionId);
		$collection = $this->collection(id: $collectionId, collections: $collections);
		if ($collection === null) {
			return $label.': the collection is not one of this contribution\'s own';
		}

		if ($this->filled(value: ($collection['scopeClaim'] ?? null)) === true || $this->filled(value: ($collection['via'] ?? null)) === true) {
			return $label.': the collection is not scoped by the subject reference on the record';
		}

		$on = ($rule['on'] ?? null);
		$field = '';
		$operator = '';
		if (is_array($on) === true) {
			$field = (string)($on['field'] ?? '');
			$operator = (string)($on['operator'] ?? '');
		}

		if ($operator !== self::OPERATOR_CHANGED) {
			return $label.': the only operator is "changed"';
		}

		if ($this->projects(collection: $collection, field: $field) === false) {
			return $label.': the field "'.$field.'" is not projected to the resident';
		}

		return null;
	}//end refusal()

	/**
	 * The kept rule, reduced to the keys the listener reads.
	 *
	 * @param array<string, mixed>      $rule       The rule.
	 * @param array<string, mixed>|null $collection Its collection.
	 *
	 * @return array<string, mixed>
	 */
	private function shape(array $rule, ?array $collection): array {
		$shaped = [
			'ruleKey' => (string)$rule['ruleKey'],
			'collection' => (string)$rule['collection'],
			'on' => ['field' => (string)$rule['on']['field'], 'operator' => self::OPERATOR_CHANGED],
		];

		// A title field is shown in the resident's inbox, so it has to be one
		// the resident may see. An unprojected one is left off; the collection
		// label stands in.
		$titleField = ($rule['titleField'] ?? null);
		if (is_string($titleField) === true && $collection !== null && $this->projects(collection: $collection, field: $titleField) === true) {
			$shaped['titleField'] = $titleField;
		}

		return $shaped;
	}//end shape()

	/**
	 * The collection with this id, or null.
	 *
	 * @param string                           $id          The collection id.
	 * @param array<int, array<string, mixed>> $collections The collections.
	 *
	 * @return array<string, mixed>|null
	 */
	private function collection(string $id, array $collections): ?array {
		foreach ($collections as $collection) {
			if (is_array($collection) === true && (string)($collection['id'] ?? '') === $id) {
				return $collection;
			}
		}

		return null;
	}//end collection()

	/**
	 * Whether the collection projects this field to the resident.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param string               $field      The field.
	 *
	 * @return bool
	 */
	private function projects(array $collection, string $field): bool {
		$fields = ($collection['fields'] ?? null);
		if ($field === '' || is_array($fields) === false) {
			return false;
		}

		return in_array($field, $fields, true);
	}//end projects()

	/**
	 * Whether a scoping key carries a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function filled(mixed $value): bool {
		return $value !== null && $value !== '' && $value !== [];
	}//end filled()
}//end class
