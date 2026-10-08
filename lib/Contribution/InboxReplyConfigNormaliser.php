<?php

/**
 * Portaliq Inbox Reply Config Normaliser
 *
 * Keeps the `reply` an inbox collection declares only when it names a create
 * action of the same contribution.
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
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * `reply: {action, carry, subjectFrom}` on a `kind: inbox` collection. The
 * action must be a `type: create` action of the same contribution; a carried
 * field must be one the action whitelists and the collection projects.
 *
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t01
 */
class InboxReplyConfigNormaliser {

	/**
	 * The shape of a field or action name.
	 *
	 * @var string
	 */
	private const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

	/**
	 * Resolve every inbox collection's `reply` against the contribution's actions.
	 *
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 * @param array<int, array<string, mixed>> $actions The sanitised actions.
	 *
	 * @return array<int, array<string, mixed>> The collections, with a well-formed `reply` or none.
	 *
	 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t01
	 */
	public function resolve(array $collections, array $actions): array {
		foreach ($collections as $index => $collection) {
			if (is_array($collection) === false || array_key_exists('reply', $collection) === false) {
				continue;
			}

			$declared = $collection['reply'];
			unset($collections[$index]['reply']);
			if (($collection['kind'] ?? '') !== 'inbox') {
				continue;
			}

			$reply = $this->reply(declared: $declared, collection: $collection, actions: $actions);
			if ($reply !== null) {
				$collections[$index]['reply'] = $reply;
			}
		}

		return $collections;
	}//end resolve()

	/**
	 * One declaration, or null to drop it.
	 *
	 * @param mixed $declared The declared `reply`.
	 * @param array<string, mixed> $collection The inbox collection.
	 * @param array<int, array<string, mixed>> $actions The contribution's actions.
	 *
	 * @return array<string, mixed>|null The kept declaration.
	 */
	private function reply(mixed $declared, array $collection, array $actions): ?array {
		if (is_array($declared) === false || is_string($declared['action'] ?? null) === false) {
			return null;
		}

		$action = null;
		foreach ($actions as $candidate) {
			if (is_array($candidate) === true && ($candidate['id'] ?? null) === $declared['action'] && ($candidate['type'] ?? '') === 'create') {
				$action = $candidate;
			}
		}

		if ($action === null) {
			return null;
		}

		$whitelist = array_values(array_filter((array)($action['fields'] ?? []), 'is_string'));
		$projected = ($collection['fields'] ?? null);
		$carry     = [];
		foreach ((array)($declared['carry'] ?? []) as $replyField => $messageField) {
			$named = ($this->name(value: $replyField) === true && $this->name(value: $messageField) === true);
			if ($named === true && in_array($replyField, $whitelist, true) === true && $this->projects(projected: $projected, field: $messageField) === true) {
				$carry[$replyField] = $messageField;
			}
		}

		$kept = ['action' => $declared['action'], 'carry' => $carry];
		$from = ($declared['subjectFrom'] ?? null);
		if ($this->name(value: $from) === true && $this->projects(projected: $projected, field: $from) === true) {
			$kept['subjectFrom'] = $from;
		}

		return $kept;
	}//end reply()

	/**
	 * Whether a value is a plain field name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function name(mixed $value): bool {
		return is_string($value) === true && preg_match(self::NAME, $value) === 1;
	}//end name()

	/**
	 * Whether the collection projects a field; one that projects nothing offers every field.
	 *
	 * @param mixed $projected The collection's `fields`.
	 * @param string $field The field.
	 *
	 * @return bool
	 */
	private function projects(mixed $projected, string $field): bool {
		if (is_array($projected) === false || $projected === []) {
			return true;
		}

		return in_array($field, $projected, true);
	}//end projects()
}//end class
