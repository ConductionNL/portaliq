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
	public const KEYS = ['subject', 'body', 'receivedAt', 'readAt', 'attachments', 'readReceiptRequested'];

	/**
	 * The extra message fields a collection names at its top level, and the
	 * row key each one is served under.
	 */
	public const EXTRA = [
		'senderRoleField' => 'senderRole',
		'aboutField'      => 'about',
		'aboutLinkField'  => 'aboutLink',
		'actionField'     => 'action',
		'tabField'        => 'tab',
	];

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
		$collection = $this->normaliseExtra(collection: $collection);
		if (array_key_exists('messageFields', $collection) === false) {
			return $collection;
		}

		$declared = $collection['messageFields'];
		unset($collection['messageFields']);
		if (($collection['kind'] ?? null) !== 'inbox' || is_array($declared) === false) {
			return $collection;
		}

		$kept = $this->keptFields(declared: $declared);
		if ($kept !== []) {
			$collection['messageFields'] = $kept;
		}

		return $collection;
	}//end normalise()

	/**
	 * The inbox fields a declaration names with a plain field name.
	 *
	 * @param array<string, mixed> $declared The declared `messageFields`.
	 *
	 * @return array<string, string> The kept field names, by inbox key.
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	private function keptFields(array $declared): array {
		$kept = [];
		foreach (self::KEYS as $key) {
			$field = ($declared[$key] ?? null);
			if ($this->isFieldName(value: $field) === true) {
				$kept[$key] = $field;
			}
		}

		return $kept;
	}//end keptFields()

	/**
	 * Whether a declared value is a plain field name.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return bool
	 */
	private function isFieldName(mixed $value): bool {
		return is_string($value) === true && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
	}//end isFieldName()

	/**
	 * Keep the plain field names of `senderRoleField`, `aboutField`,
	 * `aboutLinkField`, `actionField` and `tabField` on an inbox collection;
	 * drop each malformed one, and all of them anywhere else.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 */
	private function normaliseExtra(array $collection): array {
		$isInbox = (($collection['kind'] ?? null) === 'inbox');
		foreach (array_keys(self::EXTRA) as $key) {
			if (array_key_exists($key, $collection) === false) {
				continue;
			}

			$field = $collection[$key];
			unset($collection[$key]);
			if ($isInbox === true && $this->isFieldName(value: $field) === true) {
				$collection[$key] = $field;
			}
		}

		return $collection;
	}//end normaliseExtra()

	/**
	 * An action as the app projected it, kept only when it is a label and a
	 * path inside this portal.
	 *
	 * An absolute address, a protocol-relative one and a script address all
	 * return null, so the site never draws a button that leaves the portal.
	 *
	 * @param mixed $action The projected value.
	 *
	 * @return array{label: string, href: string}|null
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 */
	public static function sameSiteAction(mixed $action): ?array {
		return (new InboxActionLink())->sameSite(action: $action);
	}//end sameSiteAction()

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
		$row = $this->applyExtra(row: $row, collection: $collection);

		$fields = ($collection['messageFields'] ?? null);
		if (is_array($fields) === false) {
			return $row;
		}

		return $this->withMessageFields(row: $row, fields: $fields);
	}//end apply()

	/**
	 * Copy each named field onto the row under its inbox name, in the
	 * declared order; `readAt` sets `read` from that field alone.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $fields The collection's `messageFields`.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	private function withMessageFields(array $row, array $fields): array {
		foreach ($fields as $key => $field) {
			if ($key === 'readAt') {
				$row['read'] = $this->filled(value: ($row[$field] ?? null));
				continue;
			}

			if (is_string($field) === true && array_key_exists($field, $row) === true) {
				$row[$key] = $row[$field];
			}
		}

		return $row;
	}//end withMessageFields()

	/**
	 * Copy the extra fields onto the row; the action only when it stays in the portal.
	 *
	 * @param array<string, mixed> $row        The row.
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 */
	private function applyExtra(array $row, array $collection): array {
		foreach (self::EXTRA as $key => $rowKey) {
			$field = ($collection[$key] ?? null);
			if (is_string($field) === false || array_key_exists($field, $row) === false) {
				continue;
			}

			if ($rowKey === 'action') {
				$row = $this->withAction(row: $row, value: $row[$field]);
				continue;
			}

			$row[$rowKey] = $row[$field];
		}

		return $row;
	}//end applyExtra()

	/**
	 * The row with the projected action, only when it stays in the portal.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param mixed                $value The projected action.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 */
	private function withAction(array $row, mixed $value): array {
		$action = self::sameSiteAction(action: $value);
		if ($action !== null) {
			$row['action'] = $action;
		}

		return $row;
	}//end withAction()

	/**
	 * The literal payload of a mark-read.
	 *
	 * Never the request body: the client cannot write a field through this
	 * path. Rules:
	 *  1. An app's inbox that names `messageFields.readAt` gets the current
	 *     time in that field, unless it already holds a value (the first
	 *     moment stays).
	 *  2. Portaliq's own message with `readReceiptRequested` and no `readAt`
	 *     gets `read: true` and the current time in `readAt`.
	 *  3. Any other message gets `read: true` only. The moment of opening is
	 *     personal data and is kept only when the sender asked.
	 *
	 * @param array<string, mixed> $collection The normalised inbox collection.
	 * @param string               $now        The current time, ISO 8601.
	 * @param array<string, mixed> $row        The stored message, as the owner reads it.
	 *
	 * @return array<string, mixed> The fields to write; empty when nothing changes.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t03
	 */
	public function readPayload(array $collection, string $now, array $row=[]): array {
		$field = ($collection['messageFields']['readAt'] ?? null);
		if (is_string($field) === true && $field !== '') {
			if ($this->filled(value: ($row[$field] ?? null)) === true) {
				return [];
			}

			return [$field => $now];
		}

		return $this->ownReadPayload(row: $row, now: $now);
	}//end readPayload()

	/**
	 * The mark-read payload of a message that names no read field: the
	 * moment only when the sender asked for a receipt and none is kept yet.
	 *
	 * @param array<string, mixed> $row The stored message.
	 * @param string               $now The current time, ISO 8601.
	 *
	 * @return array<string, mixed> The fields to write.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t03
	 */
	private function ownReadPayload(array $row, string $now): array {
		if (($row['readReceiptRequested'] ?? false) === true && $this->filled(value: ($row['readAt'] ?? null)) === false) {
			return ['read' => true, 'readAt' => $now];
		}

		return ['read' => true];
	}//end ownReadPayload()

	/**
	 * Whether a stored value is set.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function filled(mixed $value): bool {
		return $value !== null && $value !== '' && $value !== false;
	}//end filled()
}//end class
