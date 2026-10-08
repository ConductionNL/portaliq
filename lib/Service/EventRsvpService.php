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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
class EventRsvpService {
	use PagedObjectReads;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'eventRsvp';

	private const ALLOWED_RESPONSES = ['yes', 'no', 'maybe'];

	/**
	 * The refusals attempt() answers with; null is a recorded answer.
	 */
	public const REASON_NOT_FOUND = 'not_found';

	public const REASON_FULL = 'event-full';

	public const REASON_CLOSED = 'signup-closed';

	public const REASON_SEATS = 'seats-invalid';

	/**
	 * The seats one answer may ask for when the event names no maximum.
	 */
	private const DEFAULT_MAX_SEATS = 4;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param EventFeedReader $feedReader Re-verifies the event is in the guardian's own audience.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly EventFeedReader $feedReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Upsert an RSVP. Returns false for EVERY failure shape (not found,
	 * not in audience, `rsvpEnabled` false, not the guardian's own child,
	 * invalid response, closed, full) — kept for callers that need no
	 * reason; the controller uses attempt().
	 *
	 * @param string   $subjectRef The answering person's own subjectRef.
	 * @param string   $eventId    The event id.
	 * @param string   $childRef   The child the RSVP is for.
	 * @param string   $response   One of `yes`, `no`, `maybe`.
	 * @param int|null $seats      The seats asked, when the event asks for seats.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	public function rsvp(string $subjectRef, string $eventId, string $childRef, string $response, ?int $seats = null): bool {
		$reason = $this->attempt(subjectRef: $subjectRef, eventId: $eventId, childRef: $childRef, response: $response, seats: $seats);

		return $reason === null;
	}//end rsvp()

	/**
	 * Record one answer per child per event, whoever gave it: a guardian for
	 * her own child, or the learner herself when the event allows it. A later
	 * answer replaces the earlier one. With seats asked the answer carries
	 * 1 to the event's maximum, and one that would pass the event's capacity
	 * is refused. After the deadline every answer is refused.
	 *
	 * @param string      $subjectRef The answering person's own subjectRef.
	 * @param string      $eventId    The event id.
	 * @param string      $childRef   The child the answer is for.
	 * @param string      $response   One of `yes`, `no`, `maybe`.
	 * @param int|null    $seats      The seats asked, when the event asks for seats.
	 * @param string|null $now        The moment, ISO 8601; defaults to the clock.
	 *
	 * @return string|null Null when recorded, else one of the REASON_ constants.
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats
	 */
	public function attempt(string $subjectRef, string $eventId, string $childRef, string $response, ?int $seats = null, ?string $now = null): ?string {
		$event = $this->answerableEvent(subjectRef: $subjectRef, eventId: $eventId, childRef: $childRef, response: $response);
		if ($event === null) {
			return self::REASON_NOT_FOUND;
		}

		if (EventDeadline::hasPassed(deadline: (string)($event['signupDeadline'] ?? ''), now: $now) === true) {
			return self::REASON_CLOSED;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return self::REASON_NOT_FOUND;
		}

		$asked = $this->seatsToRecord(event: $event, response: $response, seats: $seats);
		if ($asked === false) {
			return self::REASON_SEATS;
		}

		$existing = $this->findExistingRsvp(objectService: $objectService, eventId: $eventId, childRef: $childRef);
		$full = false;
		if ($response === 'yes') {
			$full = $this->passesCapacity(objectService: $objectService, event: $event, eventId: $eventId, asked: $asked, existing: $existing);
		}

		if ($full === true) {
			return self::REASON_FULL;
		}

		$existingId = null;
		if ($existing !== null) {
			$existingId = $this->rowId(row: $existing);
		}

		$object = [
			'eventRef' => $eventId,
			'guardianRef' => $subjectRef,
			'childRef' => $childRef,
			'response' => $response,
			'respondedAt' => gmdate('c'),
		];
		if ($asked !== null) {
			$object['seats'] = $asked;
		}

		try {
			$objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event RSVP save failed', ['reason' => $e->getMessage()]);
			return self::REASON_NOT_FOUND;
		}

		return null;
	}//end attempt()

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
	 */
	private function answerableEvent(string $subjectRef, string $eventId, string $childRef, string $response): ?array {
		if ($subjectRef === '' || $eventId === '' || $childRef === '' || in_array($response, self::ALLOWED_RESPONSES, true) === false) {
			return null;
		}

		$event = $this->feedReader->readOwnEvent(subjectRef: $subjectRef, id: $eventId);
		if ($event === null || ($event['rsvpEnabled'] ?? false) !== true) {
			return null;
		}

		$by = $this->stringList(value: ($event['rsvpBy'] ?? null));
		if ($by === []) {
			$by = ['guardian'];
		}

		if ($childRef === $subjectRef && in_array('learner', $by, true) === true) {
			return $event;
		}

		if (in_array('guardian', $by, true) === true && $this->feedReader->isOwnChild(subjectRef: $subjectRef, childRef: $childRef) === true) {
			return $event;
		}

		return null;
	}//end answerableEvent()

	/**
	 * The seats to record: null when the event does not ask for seats, 0 for
	 * an answer other than yes, else the asked number; false when the number
	 * is outside 1 to the event's maximum.
	 *
	 * @param array<string, mixed> $event    The event.
	 * @param string               $response The response.
	 * @param int|null             $seats    The seats asked.
	 *
	 * @return int|false|null
	 */
	private function seatsToRecord(array $event, string $response, ?int $seats): int|false|null {
		if (($event['askSeats'] ?? false) !== true) {
			return null;
		}

		if ($response !== 'yes') {
			return 0;
		}

		$max = (int)($event['maxSeatsPerAnswer'] ?? self::DEFAULT_MAX_SEATS);
		if ($max < 1) {
			$max = self::DEFAULT_MAX_SEATS;
		}

		if ($seats === null || $seats < 1 || $seats > $max) {
			return false;
		}

		return $seats;
	}//end seatsToRecord()

	/**
	 * Whether this answer would take the event past its capacity. The
	 * child's own earlier answer does not count against her new one.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $event         The event.
	 * @param string               $eventId       The event id.
	 * @param int|null             $asked         The seats this answer takes; null counts one per answer.
	 * @param array|null           $existing      The child's earlier answer.
	 *
	 * @return bool
	 */
	private function passesCapacity(object $objectService, array $event, string $eventId, ?int $asked, ?array $existing): bool {
		$capacity = (int)($event['capacity'] ?? 0);
		if ($capacity < 1) {
			return false;
		}

		$taken = 0;
		foreach ($this->answersOf(objectService: $objectService, eventId: $eventId) as $row) {
			if ($existing !== null && $this->rowId(row: $row) === $this->rowId(row: $existing)) {
				continue;
			}

			if ((string)($row['response'] ?? '') !== 'yes') {
				continue;
			}

			$rowSeats = 1;
			if ($asked !== null) {
				$rowSeats = max(1, (int)($row['seats'] ?? 1));
			}

			$taken += $rowSeats;
		}

		return ($taken + ($asked ?? 1)) > $capacity;
	}//end passesCapacity()

	/**
	 * The seats taken on an event: the seats of every yes, or one per yes
	 * when the answers carry none.
	 *
	 * @param array<int, array<string, mixed>> $answers The event's answers.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats
	 */
	public static function seatsTaken(array $answers): int {
		$taken = 0;
		foreach ($answers as $row) {
			if ((string)($row['response'] ?? '') === 'yes') {
				$taken += max(1, (int)($row['seats'] ?? 1));
			}
		}

		return $taken;
	}//end seatsTaken()

	/**
	 * Every answer on one event.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $eventId       The event id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function answersOf(object $objectService, string $eventId): array {
		try {
			$rows = $this->readEveryPage(objectService: $objectService, register: self::REGISTER, schema: self::SCHEMA, filters: ['eventRef' => $eventId]);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event RSVP count failed', ['reason' => $e->getMessage()]);
			return [];
		}

		$out = [];
		if (is_array($rows) === false) {
			return [];
		}

		foreach ($rows as $row) {
			$row = $this->normalise(row: $row);
			if ($row !== null && (string)($row['eventRef'] ?? '') === $eventId) {
				$out[] = $row;
			}
		}

		return $out;
	}//end answersOf()

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

	/**
	 * The existing answer for this child on this event, whoever gave it.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $eventId       The event id.
	 * @param string $childRef      The child.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findExistingRsvp(object $objectService, string $eventId, string $childRef): ?array {
		foreach ($this->answersOf(objectService: $objectService, eventId: $eventId) as $row) {
			if ((string)($row['childRef'] ?? '') === $childRef) {
				return $row;
			}
		}

		return null;
	}//end findExistingRsvp()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
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

		return null;
	}//end rowId()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
