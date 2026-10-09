<?php

/**
 * Portaliq Row Action Resolver (contribution-pay-screen)
 *
 * Decides which actions a collection offers on its rows. A row action is
 * either a `type: update` action (a server-enforced transition,
 * portal-status-transitions) or an endpoint row action: an action with an
 * instance-local endpoint that declares `rowField`, the body key the portal
 * stamps the proven row's id under. Shillinq's `pay` and filinq's `sign` are
 * endpoint row actions.
 *
 * INVARIANT: presentation-only, like the rest of the v3 normaliser. It never
 * adds an action, never alters an action's endpoint, method or minTrust, and
 * never throws. Every reject path returns the safe subset: an entry that does
 * not resolve is dropped, and a collection left without row actions loses the
 * key.
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
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Resolves `rowActions` (and the singular `rowAction`) against the actions of
 * the same contribution, and sanitises `noticeField`.
 *
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions
 */
class RowActionResolver {
	/**
	 * A field name the portal may read or stamp.
	 */
	private const FIELD_NAME = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

	/**
	 * Action types that are never endpoint row actions: they have their own
	 * surfaces (the form, the transition, the proposal queue).
	 */
	private const NON_ENDPOINT_TYPES = ['create', 'update', 'propose-change'];

	/**
	 * Resolve every collection's row actions against the sanitised actions.
	 *
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 * @param array<int, array<string, mixed>> $actions The sanitised actions.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions
	 */
	public function resolve(array $collections, array $actions): array {
		$rowIds = $this->rowActionIds(actions: $actions);
		foreach ($collections as $index => $collection) {
			$collections[$index] = $this->resolveCollection(collection: $collection, rowIds: $rowIds);
		}

		return $collections;
	}//end resolve()

	/**
	 * Keep `noticeField` only when it is a field name.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-name-a-notice-field
	 */
	public function normaliseNoticeField(array $collection): array {
		if (array_key_exists('noticeField', $collection) === false) {
			return $collection;
		}

		if ($this->isFieldName(value: $collection['noticeField']) === false) {
			unset($collection['noticeField']);
		}

		return $collection;
	}//end normaliseNoticeField()

	/**
	 * Whether an action is an endpoint row action: an instance-local endpoint,
	 * a type with no surface of its own, a well-formed `rowField`, and a
	 * `rowWhen` that is absent or well-formed.
	 *
	 * @param array<string, mixed> $action The sanitised action.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function isEndpointRowAction(array $action): bool {
		if (in_array(($action['type'] ?? ''), self::NON_ENDPOINT_TYPES, true) === true) {
			return false;
		}

		if ($this->isLocalEndpoint(endpoint: ($action['endpoint'] ?? null)) === false
			|| $this->isFieldName(value: ($action['rowField'] ?? null)) === false
		) {
			return false;
		}

		return array_key_exists('rowWhen', $action) === false || $this->isRowWhen(value: $action['rowWhen']) === true;
	}//end isEndpointRowAction()

	/**
	 * Whether a row matches an action's `rowWhen`. An action without one
	 * applies to every row; a field the row does not carry never matches.
	 *
	 * @param array<string, mixed> $action The endpoint row action.
	 * @param array<string, mixed> $row The row, already read under the subject's scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-must-be-offered-only-on-the-rows-its-rowwhen-names
	 */
	public function rowMatches(array $action, array $row): bool {
		if (array_key_exists('rowWhen', $action) === false) {
			return true;
		}

		if ($this->isRowWhen(value: $action['rowWhen']) === false) {
			return false;
		}

		$value = ($row[$action['rowWhen']['field']] ?? null);
		if (is_scalar($value) === false) {
			return false;
		}

		return in_array($value, $action['rowWhen']['in'], true) === true;
	}//end rowMatches()

	/**
	 * The ids of the actions a row may offer: update actions and endpoint row
	 * actions.
	 *
	 * @param array<int, array<string, mixed>> $actions The sanitised actions.
	 *
	 * @return array<int, string>
	 */
	private function rowActionIds(array $actions): array {
		$ids = [];
		foreach ($actions as $action) {
			$id = ($action['id'] ?? null);
			if (is_string($id) === false || $id === '') {
				continue;
			}

			if (($action['type'] ?? null) === 'update' || $this->isEndpointRowAction(action: $action) === true) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end rowActionIds()

	/**
	 * Resolve one collection: fold the singular `rowAction` into the list,
	 * reduce object entries to their id, keep the ids that resolve.
	 *
	 * @param array<string, mixed> $collection The sanitised collection.
	 * @param array<int, string> $rowIds The resolvable ids.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveCollection(array $collection, array $rowIds): array {
		$entries = [];
		if (array_key_exists('rowActions', $collection) === true && is_array($collection['rowActions']) === true) {
			$entries = $collection['rowActions'];
		}

		if (array_key_exists('rowAction', $collection) === true) {
			$entries[] = $collection['rowAction'];
			unset($collection['rowAction']);
		}

		$resolved = [];
		foreach ($entries as $entry) {
			$id = $this->entryId(entry: $entry);
			if ($id !== null && in_array($id, $rowIds, true) === true && in_array($id, $resolved, true) === false) {
				$resolved[] = $id;
			}
		}

		unset($collection['rowActions']);
		if ($resolved !== []) {
			$collection['rowActions'] = $resolved;
		}

		return $collection;
	}//end resolveCollection()

	/**
	 * The id an entry names: a string, or an object's string `id`. Anything
	 * else an entry carries (an inline endpoint) is ignored.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return string|null
	 */
	private function entryId(mixed $entry): ?string {
		if (is_array($entry) === true) {
			$entry = ($entry['id'] ?? null);
		}

		if (is_string($entry) === false || $entry === '') {
			return null;
		}

		return $entry;
	}//end entryId()

	/**
	 * Whether a value is a field name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isFieldName(mixed $value): bool {
		return is_string($value) === true && preg_match(self::FIELD_NAME, $value) === 1;
	}//end isFieldName()

	/**
	 * Whether a value is a well-formed `rowWhen`: a field name and a non-empty
	 * list of scalars.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return bool
	 */
	private function isRowWhen(mixed $value): bool {
		if (is_array($value) === false || $this->isFieldName(value: ($value['field'] ?? null)) === false) {
			return false;
		}

		$allowed = ($value['in'] ?? null);
		if (is_array($allowed) === false || $allowed === [] || array_is_list($allowed) === false) {
			return false;
		}

		return count(array_filter($allowed, static fn ($v): bool => is_scalar($v) === false)) === 0;
	}//end isRowWhen()

	/**
	 * Whether an endpoint is an instance-local absolute path: a leading slash,
	 * no protocol-relative `//`, no scheme.
	 *
	 * @param mixed $endpoint The declared endpoint.
	 *
	 * @return bool
	 */
	private function isLocalEndpoint(mixed $endpoint): bool {
		return is_string($endpoint) === true
			&& str_starts_with($endpoint, '/') === true
			&& str_starts_with($endpoint, '//') === false
			&& str_contains($endpoint, '://') === false;
	}//end isLocalEndpoint()
}//end class
