<?php

/**
 * Event RSVP Service
 *
 * Upserts a guardian's RSVP for one child on one event: a second RSVP for
 * the same guardian+child+event UPDATES the existing record rather than
 * creating a second one. The generic contribution-contract writer's plain
 * merge-update cannot express "find the existing row by a compound key,
 * else create" — hence this dedicated service, the RSVP counterpart to the
 * sibling change's `NewsReadReceiptService`.
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
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Finds the event a person may answer for a child, or nothing.
 *
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
class EventRsvpEligibility {
	/**
	 * The responses an answer may carry.
	 *
	 * @var string[]
	 */
	private const ALLOWED_RESPONSES = ['yes', 'no', 'maybe'];

	/**
	 * Constructor.
	 *
	 * @param EventFeedReader $feedReader Reads the events a guardian may see.
	 */
	public function __construct(
		private readonly EventFeedReader $feedReader,
	) {
	}//end __construct()

	/**
	 * The event this person may answer for this child, or null: a known
	 * response, an event in their own audience with RSVP on, and either their
	 * own child (a guardian, when the event lets guardians answer) or
	 * themselves (a learner, when it lets learners answer).
	 *
	 * @param string $subjectRef The answering person's own subjectRef.
	 * @param string $eventId    The event id.
	 * @param string $childRef   The child the RSVP is for.
	 * @param string $response   The response.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	public function answerableEvent(string $subjectRef, string $eventId, string $childRef, string $response): ?array {
		$named = ($subjectRef !== '' && $eventId !== '' && $childRef !== '');
		if ($named === false || in_array($response, self::ALLOWED_RESPONSES, true) === false) {
			return null;
		}

		$event = $this->feedReader->readOwnEvent(subjectRef: $subjectRef, id: $eventId);
		if ($event === null || ($event['rsvpEnabled'] ?? false) !== true) {
			return null;
		}

		if ($this->mayAnswer(event: $event, subjectRef: $subjectRef, childRef: $childRef) === true) {
			return $event;
		}

		return null;
	}//end answerableEvent()

	/**
	 * Whether the person may answer for the child: the child themselves when
	 * the event lets learners answer, or their guardian when it lets guardians.
	 *
	 * @param array<string, mixed> $event      The event.
	 * @param string               $subjectRef The answering person's own subjectRef.
	 * @param string               $childRef   The child the RSVP is for.
	 *
	 * @return bool
	 */
	private function mayAnswer(array $event, string $subjectRef, string $childRef): bool {
		$answerers = $this->stringList(value: ($event['rsvpBy'] ?? null));
		if ($answerers === []) {
			$answerers = ['guardian'];
		}

		if ($childRef === $subjectRef && in_array('learner', $answerers, true) === true) {
			return true;
		}

		return in_array('guardian', $answerers, true) === true && $this->feedReader->isOwnChild(subjectRef: $subjectRef, childRef: $childRef) === true;
	}//end mayAnswer()

	/**
	 * @param mixed $value A list.
	 *
	 * @return array<int, string>
	 */
	private function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_string'));
	}//end stringList()
}//end class
