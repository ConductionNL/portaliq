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
			if ($isInbox === true && is_string($field) === true && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) === 1) {
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
		if (is_array($action) === false) {
			return null;
		}

		$label = $action['label'] ?? null;
		$href  = $action['href'] ?? null;
		if (is_string($label) === false || trim($label) === '' || is_string($href) === false) {
			return null;
		}

		$isPath = (preg_match('#^/(?!/)#', $href) === 1 && str_contains($href, '\\') === false && preg_match('/[[:cntrl:]]/', $href) === 0);
		$isHash = (preg_match('/^#[A-Za-z0-9_\/=.-]+$/', $href) === 1);
		if ($isPath === false && $isHash === false) {
			return null;
		}

		return ['label' => trim($label), 'href' => $href];
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
				$action = self::sameSiteAction(action: $row[$field]);
				if ($action !== null) {
					$row['action'] = $action;
				}

				continue;
			}

			$row[$rowKey] = $row[$field];
		}

		return $row;
	}//end applyExtra()

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
