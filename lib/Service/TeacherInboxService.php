<?php

/**
 * Teacher Inbox Service
 *
 * A pure aggregation over {@see GuardianMessagingLeafInterface}: every
 * thread a staff member participates in, bucketed by group (direct threads
 * under their own bucket), each annotated with a fresh unread count.
 * Introduces NO new authorization surface — it can only ever see what
 * `listThreads()`/`listMessages()` already scope to the calling staff
 * member's own participation.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/teacher-inbox-per-group/specs/guardian-direct-messaging/spec.md#requirement-a-staff-members-inbox-is-bucketed-by-group-with-an-unread-count
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;

/**
 * @spec openspec/changes/teacher-inbox-per-group/specs/guardian-direct-messaging/spec.md#requirement-a-staff-members-inbox-is-bucketed-by-group-with-an-unread-count
 */
class TeacherInboxService {
	private const DIRECT_BUCKET = 'direct';

	/**
	 * Constructor.
	 *
	 * @param GuardianMessagingLeafInterface $messaging The messaging leaf.
	 */
	public function __construct(
		private readonly GuardianMessagingLeafInterface $messaging,
	) {
	}//end __construct()

	/**
	 * Build the staff member's own inbox, bucketed by group.
	 *
	 * @param string $staffRef The calling staff member's own subjectRef.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Bucket key => threads (each carrying `unreadCount`).
	 *
	 * @spec openspec/changes/teacher-inbox-per-group/specs/guardian-direct-messaging/spec.md#requirement-a-staff-members-inbox-is-bucketed-by-group-with-an-unread-count
	 */
	public function inboxFor(string $staffRef): array {
		if ($staffRef === '') {
			return [];
		}

		$buckets = [];
		foreach ($this->messaging->listThreads(subjectRef: $staffRef, isStaff: true) as $thread) {
			$bucket = self::DIRECT_BUCKET;
			if (($thread['kind'] ?? '') === 'group' && (string)($thread['groupRef'] ?? '') !== '') {
				$bucket = (string)$thread['groupRef'];
			}

			$thread['unreadCount'] = $this->unreadCount(thread: $thread, staffRef: $staffRef);
			$buckets[$bucket][] = $thread;
		}

		return $buckets;
	}//end inboxFor()

	/**
	 * The number of a thread's messages the staff member has not read yet.
	 *
	 * @param array<string, mixed> $thread The thread row.
	 * @param string $staffRef The calling staff member's own subjectRef.
	 *
	 * @return int
	 */
	private function unreadCount(array $thread, string $staffRef): int {
		$threadId = $this->rowId(row: $thread);
		if ($threadId === '') {
			return 0;
		}

		$messages = $this->messaging->listMessages(threadId: $threadId, subjectRef: $staffRef, isStaff: true);
		if ($messages === null) {
			return 0;
		}

		$unread = 0;
		foreach ($messages as $message) {
			$readBy = [];
			if (is_array($message['readBy'] ?? null) === true) {
				$readBy = $message['readBy'];
			}

			if (in_array($staffRef, $readBy, true) === false) {
				$unread++;
			}
		}

		return $unread;
	}//end unreadCount()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function rowId(array $row): string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		if (isset($row['uuid']) === true) {
			return (string)$row['uuid'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true && (isset($self['id']) === true || isset($self['uuid']) === true)) {
			return (string)($self['id'] ?? $self['uuid']);
		}

		return '';
	}//end rowId()
}//end class
