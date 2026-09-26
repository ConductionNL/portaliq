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
 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

/**
 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
 */
class InAppMessagingLeaf implements GuardianMessagingLeafInterface {
	private const THREAD_SCHEMA = 'messageThread';

	private const MESSAGE_SCHEMA = 'message';

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
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
	 */
	public function createThread(string $kind, array $participantRefs, ?string $groupRef, string $createdBy): ?string {
		if ($kind === self::KIND_DIRECT && $this->access->canCreateDirect(participantRefs: $participantRefs, createdBy: $createdBy) === true) {
			return $this->store->save(schema: self::THREAD_SCHEMA, object: [
				'kind' => self::KIND_DIRECT,
				'participantRefs' => $participantRefs,
				'groupRef' => null,
				'createdBy' => $createdBy,
				'createdAt' => gmdate('c'),
			]);
		}

		if ($kind === self::KIND_GROUP && $this->access->canCreateGroup(groupRef: (string)$groupRef, createdBy: $createdBy) === true) {
			return $this->store->save(schema: self::THREAD_SCHEMA, object: [
				'kind' => self::KIND_GROUP,
				'participantRefs' => [],
				'groupRef' => $groupRef,
				'createdBy' => $createdBy,
				'createdAt' => gmdate('c'),
			]);
		}

		return null;
	}//end createThread()

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
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
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
	 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
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
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	public function listMessages(string $threadId, string $subjectRef, bool $isStaff): ?array {
		$thread = $this->findThread(threadId: $threadId);
		if ($thread === null || $this->access->isParticipant(thread: $thread, subjectRef: $subjectRef, isStaff: $isStaff) === false) {
			return null;
		}

		$messages = [];
		foreach ($this->store->findAll(schema: self::MESSAGE_SCHEMA) as $message) {
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
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-message-tracks-who-has-read-it
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
	 */
	private function markOneMessageRead(array $message, string $subjectRef): void {
		$readBy = [];
		if (is_array($message['readBy'] ?? null) === true) {
			$readBy = $message['readBy'];
		}

		if (in_array($subjectRef, $readBy, true) === true) {
			return;
		}

		$id = $this->store->rowId(row: $message);
		if ($id === null) {
			return;
		}

		$readBy[] = $subjectRef;
		$merged = $message;
		unset($merged['@self']);
		$merged['readBy'] = $readBy;

		$this->store->save(schema: self::MESSAGE_SCHEMA, object: $merged, uuid: $id);
	}//end markOneMessageRead()

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

		foreach ($this->store->findAll(schema: self::THREAD_SCHEMA) as $thread) {
			if ($this->store->rowId(row: $thread) === $threadId) {
				return $thread;
			}
		}

		return null;
	}//end findThread()
}//end class
