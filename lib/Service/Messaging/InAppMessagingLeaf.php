<?php

/**
 * In-App Messaging Leaf
 *
 * The FIRST implementation of {@see GuardianMessagingLeafInterface} —
 * backed by portaliq's own `messageThread`/`message` OpenRegister schemas,
 * with no external Talk dependency. Authorization is delegated to
 * {@see MessageThreadAccessGuard} and persistence to {@see MessageStore}, so
 * this class is purely orchestration: the create-time check and every
 * subsequent read/post/mark-read share the SAME participation predicate
 * (proposal.md Risks 1-2).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Messaging
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
 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

/**
 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class InAppMessagingLeaf implements GuardianMessagingLeafInterface {
	private const THREAD_SCHEMA = 'messageThread';

	private const MESSAGE_SCHEMA = 'guardianMessage';

	private const KIND_DIRECT = 'direct';

	private const KIND_GROUP = 'group';

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store OpenRegister persistence for threads/messages.
	 * @param MessageThreadAccessGuard $access Authorization decisions (create/participate).
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly MessageThreadAccessGuard $access,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $kind `direct` or `group`.
	 * @param array<int, string> $participantRefs Explicit participants (direct threads).
	 * @param string|null $groupRef The scoping group (group threads).
	 * @param string $createdBy The creating subject's own subjectRef.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
	 */
	public function createThread(string $kind, array $participantRefs, ?string $groupRef, string $createdBy): ?string {
		if ($this->mayCreateDirect(kind: $kind, participantRefs: $participantRefs, createdBy: $createdBy) === true) {
			return $this->saveDirect(participantRefs: $participantRefs, createdBy: $createdBy);
		}

		if ($this->mayCreateGroup(kind: $kind, groupRef: $groupRef, createdBy: $createdBy) === true) {
			return $this->saveGroup(groupRef: $groupRef, createdBy: $createdBy);
		}

		return null;
	}//end createThread()

	/**
	 * Whether this is a direct thread the creator may start.
	 *
	 * @param string             $kind            `direct` or `group`.
	 * @param array<int, string> $participantRefs Explicit participants.
	 * @param string             $createdBy       The creating subject's own subjectRef.
	 *
	 * @return bool
	 */
	private function mayCreateDirect(string $kind, array $participantRefs, string $createdBy): bool {
		return $kind === self::KIND_DIRECT && $this->access->canCreateDirect(participantRefs: $participantRefs, createdBy: $createdBy) === true;
	}//end mayCreateDirect()

	/**
	 * Whether this is a group thread the creator may start.
	 *
	 * @param string      $kind      `direct` or `group`.
	 * @param string|null $groupRef  The scoping group.
	 * @param string      $createdBy The creating subject's own subjectRef.
	 *
	 * @return bool
	 */
	private function mayCreateGroup(string $kind, string|null $groupRef, string $createdBy): bool {
		return $kind === self::KIND_GROUP && $this->access->canCreateGroup(groupRef: (string)$groupRef, createdBy: $createdBy) === true;
	}//end mayCreateGroup()

	/**
	 * Save a direct thread.
	 *
	 * @param array<int, string> $participantRefs Explicit participants.
	 * @param string             $createdBy       The creating subject's own subjectRef.
	 *
	 * @return string|null
	 */
	private function saveDirect(array $participantRefs, string $createdBy): string|null {
		return $this->store->save(schema: self::THREAD_SCHEMA, object: [
			'kind' => self::KIND_DIRECT,
			'participantRefs' => $participantRefs,
			'groupRef' => null,
			'createdBy' => $createdBy,
			'createdAt' => gmdate('c'),
		]);
	}//end saveDirect()

	/**
	 * Save a group thread.
	 *
	 * @param string|null $groupRef  The scoping group.
	 * @param string      $createdBy The creating subject's own subjectRef.
	 *
	 * @return string|null
	 */
	private function saveGroup(string|null $groupRef, string $createdBy): string|null {
		return $this->store->save(schema: self::THREAD_SCHEMA, object: [
			'kind' => self::KIND_GROUP,
			'participantRefs' => [],
			'groupRef' => $groupRef,
			'createdBy' => $createdBy,
			'createdAt' => gmdate('c'),
		]);
	}//end saveGroup()

	/**
	 * {@inheritDoc}
	 *
	 * @param string                $subjectRef The resident's own subjectRef.
	 * @param array<string, string> $contact    The proven contact.
	 * @param string                $title      The subject line.
	 * @param string                $body       The first message.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	public function createContactThread(string $subjectRef, array $contact, string $title, string $body): ?string {
		$staffRef = (string)($contact['staffRef'] ?? '');
		if ($this->mayStartWith(subjectRef: $subjectRef, staffRef: $staffRef, body: $body) === false) {
			return null;
		}

		$id = $this->saveContactThread(subjectRef: $subjectRef, contact: $contact, title: $title);
		if ($id === null) {
			return null;
		}

		$this->saveFirstMessage(id: $id, subjectRef: $subjectRef, body: $body);

		return $id;
	}//end createContactThread()

	/**
	 * Whether a resident may start this conversation: both sides known, not with themselves, and something said.
	 *
	 * @param string $subjectRef The resident's own subjectRef.
	 * @param string $staffRef   The contact's subjectRef.
	 * @param string $body       The first message.
	 *
	 * @return bool
	 */
	private function mayStartWith(string $subjectRef, string $staffRef, string $body): bool {
		if ($subjectRef === '' || $staffRef === '' || $staffRef === $subjectRef || trim($body) === '') {
			return false;
		}

		return true;
	}//end mayStartWith()

	/**
	 * Save the direct thread with the contact, on the record it is about.
	 *
	 * @param string                $subjectRef The resident's own subjectRef.
	 * @param array<string, string> $contact    The proven contact.
	 * @param string                $title      The subject line.
	 *
	 * @return string|null
	 */
	private function saveContactThread(string $subjectRef, array $contact, string $title): string|null {
		return $this->store->save(schema: self::THREAD_SCHEMA, object: [
			'kind' => self::KIND_DIRECT,
			'participantRefs' => [$subjectRef, (string)($contact['staffRef'] ?? '')],
			'groupRef' => null,
			'createdBy' => $subjectRef,
			'createdAt' => gmdate('c'),
			'recordRef' => (string)($contact['recordRef'] ?? ''),
			'recordLabel' => (string)($contact['recordLabel'] ?? ''),
			'title' => $title,
			'staffName' => (string)($contact['name'] ?? ''),
			'staffRole' => (string)($contact['role'] ?? ''),
		]);
	}//end saveContactThread()

	/**
	 * Save the resident's first message, read by them.
	 *
	 * @param string $id         The thread id.
	 * @param string $subjectRef The resident's own subjectRef.
	 * @param string $body       The first message.
	 *
	 * @return void
	 */
	private function saveFirstMessage(string $id, string $subjectRef, string $body): void {
		$this->store->save(schema: self::MESSAGE_SCHEMA, object: [
			'threadRef' => $id,
			'senderRef' => $subjectRef,
			'body' => $body,
			'sentAt' => gmdate('c'),
			'readBy' => [$subjectRef],
		]);
	}//end saveFirstMessage()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $threadId The thread id.
	 * @param string $senderRef The sender's own subjectRef.
	 * @param bool $senderIsStaff Whether the sender is staff.
	 * @param string $body The message body.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	public function postMessage(string $threadId, string $senderRef, bool $senderIsStaff, string $body): bool {
		if ($threadId === '' || $senderRef === '' || $body === '') {
			return false;
		}

		$thread = $this->findThread(threadId: $threadId);
		if ($thread === null || $this->access->isParticipant(thread: $thread, subjectRef: $senderRef, isStaff: $senderIsStaff) === false) {
			return false;
		}

		return $this->store->save(schema: self::MESSAGE_SCHEMA, object: [
			'threadRef' => $threadId,
			'senderRef' => $senderRef,
			'body' => $body,
			'sentAt' => gmdate('c'),
			'readBy' => [],
		]) !== null;
	}//end postMessage()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-28-guardian-direct-messages/design.md#api-design
	 */
	public function listThreads(string $subjectRef, bool $isStaff): array {
		if ($subjectRef === '') {
			return [];
		}

		$threads = [];
		foreach ($this->store->findAll(schema: self::THREAD_SCHEMA) as $thread) {
			if ($this->access->isParticipant(thread: $thread, subjectRef: $subjectRef, isStaff: $isStaff) === true) {
				$threads[] = $thread;
			}
		}

		return $threads;
	}//end listThreads()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $threadId The thread id.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	public function listMessages(string $threadId, string $subjectRef, bool $isStaff): ?array {
		$thread = $this->findThread(threadId: $threadId);
		if ($thread === null || $this->access->isParticipant(thread: $thread, subjectRef: $subjectRef, isStaff: $isStaff) === false) {
			return null;
		}

		$messages = [];
		foreach ($this->store->findAll(schema: self::MESSAGE_SCHEMA, filters: ['threadRef' => $threadId]) as $message) {
			if ((string)($message['threadRef'] ?? '') === $threadId) {
				$messages[] = $message;
			}
		}

		return $messages;
	}//end listMessages()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $threadId The thread id.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-message-tracks-who-has-read-it
	 */
	public function markThreadRead(string $threadId, string $subjectRef, bool $isStaff): bool {
		$messages = $this->listMessages(threadId: $threadId, subjectRef: $subjectRef, isStaff: $isStaff);
		if ($messages === null) {
			return false;
		}

		foreach ($messages as $message) {
			$this->markOneMessageRead(message: $message, subjectRef: $subjectRef);
		}

		return true;
	}//end markThreadRead()

	/**
	 * Idempotently append a read receipt to one message.
	 *
	 * @param array<string, mixed> $message The message row.
	 * @param string $subjectRef The reading subject's own subjectRef.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-message-records-when-the-other-side-first-read-it
	 */
	private function markOneMessageRead(array $message, string $subjectRef): void {
		$readBy = (array)($message['readBy'] ?? []);
		$id     = $this->store->rowId(row: $message);
		if ($id === null || in_array($subjectRef, $readBy, true) === true) {
			return;
		}

		$merged = $message;
		unset($merged['@self']);
		$merged['readBy'] = [...$readBy, $subjectRef];
		$this->store->save(schema: self::MESSAGE_SCHEMA, object: $this->withReadAt(message: $merged, readerRef: $subjectRef), uuid: $id);
	}//end markOneMessageRead()

	/**
	 * The message with `readAt`, the moment someone other than its sender
	 * first read it, when it has none yet. Written once; the sender's own
	 * read never sets it.
	 *
	 * @param array<string, mixed> $message   The message row.
	 * @param string               $readerRef The reader.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-message-records-when-the-other-side-first-read-it
	 */
	private function withReadAt(array $message, string $readerRef): array {
		if ($readerRef === (string)($message['senderRef'] ?? '') || (string)($message['readAt'] ?? '') !== '') {
			return $message;
		}

		$message['readAt'] = gmdate('c');
		return $message;
	}//end withReadAt()

	/**
	 * Fetch one thread by id.
	 *
	 * @param string $threadId The thread id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findThread(string $threadId): ?array {
		if ($threadId === '') {
			return null;
		}

		// Asked by id first, so one lookup does not read every thread. The
		// id is still compared here, and a miss falls back to the full read,
		// so a store that does not narrow by id never hides a thread.
		foreach ([[$threadId], null] as $ids) {
			$thread = $this->threadAmong(ids: $ids, threadId: $threadId);
			if ($thread !== null) {
				return $thread;
			}
		}

		return null;
	}//end findThread()

	/**
	 * The thread with this id among the threads read by these ids, or among
	 * every thread when no ids are given.
	 *
	 * @param array<int, string>|null $ids      The ids to read by, or null for the full read.
	 * @param string                  $threadId The thread id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function threadAmong(?array $ids, string $threadId): ?array {
		foreach ($this->store->findAll(schema: self::THREAD_SCHEMA, filters: [], ids: $ids) as $thread) {
			if ($this->store->rowId(row: $thread) === $threadId) {
				return $thread;
			}
		}

		return null;
	}//end threadAmong()
}//end class
