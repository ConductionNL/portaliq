<?php

/**
 * Portaliq Inbox Message Fields
 *
 * The inbox screen reads one row shape: `subject`, `body`, `receivedAt`,
 * `read` and optionally `attachments`, the names of portaliq's own
 * `portalMessage`. A contributing app keeps its messages under its own
 * names (dossiq's `portaalBericht` has `content`, `sentAt` and
 * `readByRecipientAt`), so an inbox collection may declare `messageFields`:
 * which of its own fields carry each of those. Portaliq copies them onto the
 * row it serves, and mark-read writes the app's own read field (portaliq#702).
 *
 * The names come from a manifest, so only a plain field name travels on.
 * Anything else drops the entry, and a collection that is not an inbox drops
 * the whole key.
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
 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises and applies an inbox collection's `messageFields`.
 *
 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
 */
class InboxMessageFields {
	/**
	 * The inbox fields an app may name, as the inbox row calls them.
	 * `readAt` is the one exception: the row gets `read`, true when the
	 * named field holds a value.
	 */
	public const KEYS = ['subject', 'body', 'receivedAt', 'readAt', 'attachments'];

	/**
	 * Keep a well-formed `messageFields` on an inbox collection; drop each
	 * malformed entry, and the whole key anywhere else.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('messageFields', $collection) === false) {
			return $collection;
		}

		$declared = $collection['messageFields'];
		unset($collection['messageFields']);
		if (($collection['kind'] ?? null) !== 'inbox' || is_array($declared) === false) {
			return $collection;
		}

		$kept = [];
		foreach (self::KEYS as $key) {
			$field = ($declared[$key] ?? null);
			if (is_string($field) === true && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) === 1) {
				$kept[$key] = $field;
			}
		}

		if ($kept !== []) {
			$collection['messageFields'] = $kept;
		}

		return $collection;
	}//end normalise()

	/**
	 * Copy the app's own fields onto the inbox row, under the inbox's names.
	 *
	 * A field the row does not carry changes nothing. With `readAt` named,
	 * `read` follows that field alone: a date means read, nothing means
	 * unread.
	 *
	 * @param array<string, mixed> $row        The row as the collection read returned it.
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return array<string, mixed> The row in the inbox's shape.
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	public function apply(array $row, array $collection): array {
		$fields = ($collection['messageFields'] ?? null);
		if (is_array($fields) === false) {
			return $row;
		}

		foreach ($fields as $key => $field) {
			if ($key === 'readAt') {
				$value = ($row[$field] ?? null);
				$row['read'] = ($value !== null && $value !== '' && $value !== false);
				continue;
			}

			if (is_string($field) === true && array_key_exists($field, $row) === true) {
				$row[$key] = $row[$field];
			}
		}

		return $row;
	}//end apply()

	/**
	 * The field mark-read writes on a message of this collection, and its value.
	 *
	 * @param array<string, mixed> $collection The normalised collection.
	 * @param string               $now        The current time, ISO 8601.
	 *
	 * @return array<string, mixed> One field: the named `readAt` set to now, else `read: true`.
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-mark-read-writes-the-collections-own-read-field-req-imf-002
	 */
	public function readPayload(array $collection, string $now): array {
		$field = ($collection['messageFields']['readAt'] ?? null);
		if (is_string($field) === true && $field !== '') {
			return [$field => $now];
		}

		return ['read' => true];
	}//end readPayload()
}//end class
