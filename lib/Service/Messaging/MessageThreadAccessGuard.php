<?php

/**
 * Message Thread Access Guard
 *
 * Every authorization decision `InAppMessagingLeaf` needs: whether a
 * proposed direct/group thread may be created, and whether a subject
 * participates in an existing thread. Split out of the leaf itself so the
 * leaf's own complexity stays about persistence, not policy — the SAME
 * `isParticipant()` predicate backs both the create-time check and every
 * subsequent read/post/mark-read (proposal.md Risks 1-2).
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
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

use OCA\Portaliq\Service\GroupStaffFixtureReader;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */
class MessageThreadAccessGuard {
	private const KIND_DIRECT = 'direct';

	/**
	 * Constructor.
	 *
	 * @param GuardianAudienceFixtureReader $audienceReader Which groups a guardian reaches.
	 * @param GroupStaffFixtureReader $staffReader Which groups a staff member teaches.
	 */
	public function __construct(
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly GroupStaffFixtureReader $staffReader,
	) {
	}//end __construct()

	/**
	 * Whether a direct thread naming exactly these two participants may be
	 * created by `$createdBy` — true only when the OTHER participant is a
	 * staff member teaching a group the guardian's own audience reaches (in
	 * either role ordering, since the caller does not declare which side is
	 * staff).
	 *
	 * @param array<int, string> $participantRefs Exactly two subjectRefs.
	 * @param string $createdBy The creating subject's own subjectRef.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
	 */
	public function canCreateDirect(array $participantRefs, string $createdBy): bool {
		if (count($participantRefs) !== 2 || in_array($createdBy, $participantRefs, true) === false) {
			return false;
		}

		$other = $participantRefs[1];
		if ($participantRefs[0] !== $createdBy) {
			$other = $participantRefs[0];
		}

		return $this->staffTeachesAGroupTheGuardianReaches(staffRef: $other, guardianRef: $createdBy)
			|| $this->staffTeachesAGroupTheGuardianReaches(staffRef: $createdBy, guardianRef: $other);
	}//end canCreateDirect()

	/**
	 * Whether a group thread scoped to `$groupRef` may be created by
	 * `$createdBy` — true only when that staff member actually teaches it.
	 *
	 * @param string $groupRef The group.
	 * @param string $createdBy The creating staff member's own subjectRef.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-group-thread-reaches-every-in-audience-guardian-replies-are-two-way
	 */
	public function canCreateGroup(string $groupRef, string $createdBy): bool {
		return $groupRef !== '' && $this->staffReader->staffTeachesGroup(staffRef: $createdBy, groupRef: $groupRef);
	}//end canCreateGroup()

	/**
	 * Whether a subject participates in a thread — the ONE predicate every
	 * read/post/mark-read call re-checks.
	 *
	 * @param array<string, mixed> $thread The thread row.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	public function isParticipant(array $thread, string $subjectRef, bool $isStaff): bool {
		if ($subjectRef === '') {
			return false;
		}

		if (($thread['kind'] ?? '') === self::KIND_DIRECT) {
			return $this->isDirectParticipant(thread: $thread, subjectRef: $subjectRef);
		}

		return $this->isGroupParticipant(thread: $thread, subjectRef: $subjectRef, isStaff: $isStaff);
	}//end isParticipant()

	/**
	 * Direct-thread participation: an explicit membership check.
	 *
	 * @param array<string, mixed> $thread The thread row.
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return bool
	 */
	private function isDirectParticipant(array $thread, string $subjectRef): bool {
		$participants = [];
		if (is_array($thread['participantRefs'] ?? null) === true) {
			$participants = $thread['participantRefs'];
		}

		return in_array($subjectRef, $participants, true);
	}//end isDirectParticipant()

	/**
	 * Group-thread participation: staff must teach the group, a guardian
	 * must reach it.
	 *
	 * @param array<string, mixed> $thread The thread row.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param bool $isStaff Whether the subject is staff.
	 *
	 * @return bool
	 */
	private function isGroupParticipant(array $thread, string $subjectRef, bool $isStaff): bool {
		$groupRef = (string)($thread['groupRef'] ?? '');
		if ($groupRef === '') {
			return false;
		}

		if ($isStaff === true) {
			return $this->staffReader->staffTeachesGroup(staffRef: $subjectRef, groupRef: $groupRef);
		}

		return $this->audienceReader->guardianReachesGroup(subjectRef: $subjectRef, groupRef: $groupRef);
	}//end isGroupParticipant()

	/**
	 * Whether a staffRef teaches at least one group a guardianRef's own
	 * audience reaches.
	 *
	 * @param string $staffRef Candidate staff subjectRef.
	 * @param string $guardianRef Candidate guardian subjectRef.
	 *
	 * @return bool
	 */
	private function staffTeachesAGroupTheGuardianReaches(string $staffRef, string $guardianRef): bool {
		foreach ($this->staffReader->groupsTaughtBy(staffRef: $staffRef) as $groupRef) {
			if ($this->audienceReader->guardianReachesGroup(subjectRef: $guardianRef, groupRef: $groupRef) === true) {
				return true;
			}
		}

		return false;
	}//end staffTeachesAGroupTheGuardianReaches()
}//end class
