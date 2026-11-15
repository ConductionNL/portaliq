<?php

/**
 * Portaliq Case Message Entries
 *
 * Holds what a contributing app's `messages` provider answers about one record
 * to the shape the case page reads: `{id, subject, body, receivedAt,
 * fromResident, senderName, read}`, newest first. The provider's own order is
 * not trusted; an entry that is not a map, or has neither a subject nor a
 * body, is dropped rather than shown as an empty message.
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
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Shapes and orders the messages about one record.
 *
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
class CaseMessageEntries {

	/**
	 * The direction values that mean the resident sent the message.
	 */
	private const FROM_RESIDENT = ['citizen_to_handler', 'outgoing', 'sent'];

	/**
	 * The messages, held to the case page's shape, newest first.
	 *
	 * @param array<int, mixed> $entries What the provider answered.
	 *
	 * @return array<int, array<string, mixed>> The messages.
	 *
	 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
	 */
	public function entries(array $entries): array {
		$out = [];
		foreach ($entries as $entry) {
			$message = $this->entry(entry: $entry);
			if ($message !== null) {
				$out[] = $message;
			}
		}

		// Newest first; equal or missing dates keep the provider's order.
		$order = array_keys($out);
		array_multisort(array_column($out, 'receivedAt'), SORT_DESC, SORT_STRING, $order, SORT_ASC, $out);

		return $out;
	}//end entries()

	/**
	 * One message, or null when it is not a map or says nothing.
	 *
	 * @param mixed $entry One provider entry.
	 *
	 * @return array<string, mixed>|null The message.
	 */
	private function entry(mixed $entry): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$subject = $this->text(value: ($entry['subject'] ?? ''));
		$body    = $this->text(value: ($entry['body'] ?? ''));
		if ($subject === '' && $body === '') {
			return null;
		}

		return [
			'id' => $this->text(value: ($entry['id'] ?? '')),
			'subject' => $subject,
			'body' => $body,
			'receivedAt' => $this->text(value: ($entry['receivedAt'] ?? '')),
			'fromResident' => in_array(($entry['direction'] ?? null), self::FROM_RESIDENT, true),
			'senderName' => $this->text(value: ($entry['senderName'] ?? '')),
			'read' => (($entry['read'] ?? false) === true),
		];
	}//end entry()

	/**
	 * A scalar as text, anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return '';
	}//end text()
}//end class
