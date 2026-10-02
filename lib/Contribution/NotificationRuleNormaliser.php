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
 * A rule MAY name its recipients by a claim and MAY carry its own message text
 * per new value (claim-addressed-change-notices, NoticeRecipientNormaliser):
 *
 *     {"ruleKey": "conference.answered", "collection": "parentConferenceSignups",
 *      "on": {"field": "lifecycle", "operator": "changed"},
 *      "recipients": {"field": "guardianRef", "claim": "guardianRef"},
 *      "messages": {"acknowledged": {"subject": {"nl": "..."}, "body": {"nl": "..."}}}}
 *
 * Such a rule may sit on a `scopeClaim` or `via` collection: the record holds
 * the value of the recipients' claim, and the listener still reads the record
 * as each recipient before telling them (ClaimAddressedRecipients).
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
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use Psr\Log\LoggerInterface;

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
	 * Normalise a whole contribution's `notifications`, logging each dropped
	 * rule with the app that declared it. A contribution without the key is
	 * returned as it is.
	 *
	 * @param array<string, mixed> $contribution The normalised contribution.
	 * @param string               $appId        The app that contributed it.
	 * @param LoggerInterface      $logger       Where a dropped rule is reported.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
	 */
	public function normaliseContribution(array $contribution, string $appId, LoggerInterface $logger): array {
		if (array_key_exists('notifications', $contribution) === false) {
			return $contribution;
		}

		$result = $this->normalise(notifications: $contribution['notifications'], collections: (array)($contribution['collections'] ?? []), appId: $appId);
		foreach ($result['dropped'] as $reason) {
			$logger->warning('Portaliq: notification rule dropped', ['app' => $appId, 'rule' => $reason]);
		}

		$contribution['notifications'] = $result['kept'];

		return $contribution;
	}//end normaliseContribution()

	/**
	 * Normalise a contribution's `notifications` list.
	 *
	 * @param mixed                            $notifications The declared list.
	 * @param array<int, array<string, mixed>> $collections   The contribution's normalised collections.
	 * @param string                           $appId         The contributing app, whose claims a rule may address.
	 *
	 * @return array{kept: array<int, string|array<string, mixed>>, dropped: array<int, string>}
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function normalise(mixed $notifications, array $collections, string $appId = ''): array {
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

			$reason = $this->refusal(rule: $entry, collections: $collections, appId: $appId);
			if ($reason !== null) {
				$dropped[] = $reason;
				continue;
			}

			$kept[] = $this->shape(rule: $entry, collection: $this->collection(id: (string)$entry['collection'], collections: $collections))
				+ (new NoticeRecipientNormaliser())->shape(rule: $entry, appId: $appId);
		}//end foreach

		return ['kept' => $kept, 'dropped' => $dropped];
	}//end normalise()

	/**
	 * Why a rule cannot be kept, or null when it can.
	 *
	 * @param array<string, mixed>             $rule        The rule.
	 * @param array<int, array<string, mixed>> $collections The collections.
	 * @param string                           $appId       The contributing app.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(array $rule, array $collections, string $appId): ?string {
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

		// A record of a claim or via collection does not say whose it is,
		// unless the rule names its recipients by a claim the record holds.
		$addressed = array_key_exists('recipients', $rule);
		if ($addressed === false && ($this->filled(value: ($collection['scopeClaim'] ?? null)) === true || $this->filled(value: ($collection['via'] ?? null)) === true)) {
			return $label.': the collection is not scoped by the subject reference on the record';
		}

		return ($this->conditionRefusal(rule: $rule, collection: $collection, label: $label)
			?? (new NoticeRecipientNormaliser())->refusal(rule: $rule, collection: $collection, appId: $appId, label: $label));
	}//end refusal()

	/**
	 * Why a rule's `on` condition cannot be kept, or null when it can.
	 *
	 * @param array<string, mixed> $rule       The rule.
	 * @param array<string, mixed> $collection Its collection.
	 * @param string               $label      The rule, named for the log.
	 *
	 * @return string|null The reason.
	 */
	private function conditionRefusal(array $rule, array $collection, string $label): ?string {
		$condition = ($rule['on'] ?? null);
		if (is_array($condition) === false || (string)($condition['operator'] ?? '') !== self::OPERATOR_CHANGED) {
			return $label.': the only operator is "changed"';
		}

		$field = (string)($condition['field'] ?? '');
		if ($this->projects(collection: $collection, field: $field) === false) {
			return $label.': the field "'.$field.'" is not projected to the resident';
		}

		return null;
	}//end conditionRefusal()

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
