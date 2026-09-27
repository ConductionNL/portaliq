<?php

/**
 * Guardian Messaging Leaf Interface
 *
 * Names the shape a future OpenRegister `guardian-participant-messaging-leaf`
 * primitive (another lane, in flight) would need to expose, so this app's
 * controllers depend on an interface rather than a concrete OpenRegister
 * call. `InAppMessagingLeaf` is the FIRST, self-contained implementation;
 * a later OR-leaf-backed implementation can be swapped in DI
 * (`lib/AppInfo/Application.php`) with no controller change.
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
 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#messaging-leaf-interface
 */
interface GuardianMessagingLeafInterface {
	/**
	 * Create a thread. For `kind: 'direct'`, `$participantRefs` MUST contain
	 * exactly the guardian and staff subjectRefs; for `kind: 'group'`,
	 * `$groupRef` names the group. Implementations MUST re-verify, server
	 * side, that the OTHER participant (or the staff creator's own teaching
	 * assignment for a group thread) is actually reachable before creating
	 * anything — never trust the caller's claim at face value.
	 *
	 * @param string $kind `direct` or `group`.
	 * @param array<int, string> $participantRefs Explicit participants (direct threads).
	 * @param string|null $groupRef The scoping group (group threads).
	 * @param string $createdBy The creating subject's own subjectRef.
	 *
	 * @return string|null The new thread id, or null on refusal.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
	 */
	public function createThread(string $kind, array $participantRefs, ?string $groupRef, string $createdBy): ?string;

	/**
	 * Post a message. MUST re-verify the sender is a participant of the
	 * thread before writing.
	 *
	 * @param string $threadId The thread id.
	 * @param string $senderRef The sender's own subjectRef.
	 * @param bool $senderIsStaff Whether the sender is staff (changes group-thread participation check).
	 * @param string $body The message body.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	public function postMessage(string $threadId, string $senderRef, bool $senderIsStaff, string $body): bool;

	/**
	 * Every thread a subject participates in.
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
	 */
	public function listThreads(string $subjectRef, bool $isStaff): array;

	/**
	 * Every message in a thread, scoped to the calling subject's own
	 * participation. Returns null for a non-participant or non-existent
	 * thread — identically, no existence oracle.
	 *
	 * @param string $threadId The thread id.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	public function listMessages(string $threadId, string $subjectRef, bool $isStaff): ?array;

	/**
	 * Mark every message in a thread read for the calling subject.
	 * Idempotent — marking read twice never duplicates a `readBy` entry.
	 *
	 * @param string $threadId The thread id.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return bool True on success; false for a non-participant/non-existent thread.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-message-tracks-who-has-read-it
	 */
	public function markThreadRead(string $threadId, string $subjectRef, bool $isStaff): bool;
}//end interface
