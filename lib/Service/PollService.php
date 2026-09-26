<?php

/**
 * Portaliq Poll Service
 *
 * A staff member asks a question, addressed to one audience within one
 * organisation. Every portal subject of that audience answers once, and may
 * change their answer while the poll is open. Portaliq owns the whole
 * thing: no per-group targeting yet (parent-polls proposal.md Motivation —
 * that needs a `via`-style join to group membership this app does not have
 * for its own schemas today).
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
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use Exception;

/**
 * Creates polls, lists a subject's own, and records a subject's answer.
 *
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md
 */
class PollService {
	/**
	 * The register both schemas live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The poll schema.
	 */
	private const POLL_SCHEMA = 'portalPoll';

	/**
	 * The response schema.
	 */
	private const RESPONSE_SCHEMA = 'portalPollResponse';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads polls and responses.
	 * @param PortalObjectWriter $writer Writes polls and responses.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * Create a poll as the calling staff member.
	 *
	 * @param string $question What is being asked.
	 * @param array<int, array<string, string>> $options Each `{id, label}`.
	 * @param string $audience Which portal audience this poll addresses.
	 * @param string $organisation The tenant this poll is addressed within.
	 * @param string $closesAt ISO 8601, or '' for no close date.
	 * @param string $createdBy The calling staff member's Nextcloud uid.
	 *
	 * @return array{poll: array<string, mixed>}|array{error: string}
	 *
	 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-poll-is-created-by-staff-addressed-to-one-audience-and-organisation
	 */
	public function create(
		string $question,
		array $options,
		string $audience,
		string $organisation,
		string $closesAt = '',
		string $createdBy = '',
	): array {
		if ($question === '' || $audience === '' || $organisation === '' || $createdBy === '') {
			return ['error' => 'refused'];
		}

		$validOptions = $this->validOptions(options: $options);
		if (count($validOptions) < 2) {
			return ['error' => 'needs_two_options'];
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::POLL_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'question' => $question,
				'options' => $validOptions,
				'audience' => $audience,
				'organisation' => $organisation,
				'closesAt' => $closesAt,
				'createdBy' => $createdBy,
				'createdAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return ['error' => 'not_created'];
		}

		return ['poll' => $created];
	}//end create()

	/**
	 * Every poll for the subject's own audience and organisation, each
	 * carrying only the subject's own response.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-portal-subject-sees-only-polls-for-their-own-audience-and-organisation
	 */
	public function forSubject(array $subject): array {
		$audience = (string)($subject['audience'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($audience === '' || $organisation === '') {
			return [];
		}

		$polls = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::POLL_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			limit: 200,
			filter: ['audience' => $audience]
		);

		$responses = $this->responsesBySubject(subjectRef: $subjectRef);

		$out = [];
		foreach ($polls as $poll) {
			if (is_array($poll) === false) {
				continue;
			}

			$pollId = (string)($poll['uuid'] ?? $poll['id'] ?? '');
			$poll['myResponse'] = ($responses[$pollId] ?? null);
			$out[] = $poll;
		}

		return $out;
	}//end forSubject()

	/**
	 * Record the subject's answer, creating their first response or
	 * updating their existing one — never a second row for the same
	 * (poll, subject) pair.
	 *
	 * @param string $pollId The poll.
	 * @param string $optionId The chosen option.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array{responded: true}|array{error: string}
	 *
	 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-subject-may-answer-once-and-change-their-answer-while-the-poll-is-open
	 */
	public function respond(string $pollId, string $optionId, array $subject): array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($pollId === '' || $optionId === '' || $subjectRef === '') {
			return ['error' => 'refused'];
		}

		$poll = $this->pollForSubject(pollId: $pollId, subject: $subject);
		if ($poll === null) {
			return ['error' => 'not_found'];
		}

		if ($this->isClosed(poll: $poll) === true) {
			return ['error' => 'closed'];
		}

		if ($this->declaresOption(poll: $poll, optionId: $optionId) === false) {
			return ['error' => 'unknown_option'];
		}

		$existing = $this->existingResponse(pollId: $pollId, subjectRef: $subjectRef);
		$data = [
			'pollId' => $pollId,
			'subjectRef' => $subjectRef,
			'optionId' => $optionId,
			'respondedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		$written = $this->createOrUpdateResponse(existing: $existing, subjectRef: $subjectRef, data: $data);
		if ($written === null) {
			return ['error' => 'not_recorded'];
		}

		return ['responded' => true];
	}//end respond()

	/**
	 * Write the response: create the subject's first one, or update their
	 * existing row in place — never a second row for the same pair.
	 *
	 * @param array<string, mixed>|null $existing The subject's prior response, if any.
	 * @param string $subjectRef The subject's own reference.
	 * @param array<string, mixed> $data The response fields.
	 *
	 * @return array<string, mixed>|null
	 */
	private function createOrUpdateResponse(?array $existing, string $subjectRef, array $data): ?array {
		if ($existing === null) {
			return $this->writer->createObject(
				register: self::REGISTER,
				schema: self::RESPONSE_SCHEMA,
				scopeField: '',
				subjectRef: '',
				organisation: '',
				data: $data
			);
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::RESPONSE_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			id: $this->rowId(row: $existing),
			data: $data
		);
	}//end createOrUpdateResponse()

	/**
	 * Only the option entries that are genuinely `{id, label}` strings.
	 *
	 * @param array<int, mixed> $options The submitted options.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function validOptions(array $options): array {
		$out = [];
		foreach ($options as $option) {
			if (is_array($option) === false) {
				continue;
			}

			$id = (string)($option['id'] ?? '');
			$label = (string)($option['label'] ?? '');
			if ($id !== '' && $label !== '') {
				$out[] = ['id' => $id, 'label' => $label];
			}
		}

		return $out;
	}//end validOptions()

	/**
	 * The poll, re-read scoped to the subject's own audience and
	 * organisation — the SAME check `forSubject()` applies to a list,
	 * applied here to one id, so `respond()` can never be pointed at a poll
	 * outside the subject's own audience/organisation by id.
	 *
	 * @param string $pollId The poll id.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<string, mixed>|null
	 */
	private function pollForSubject(string $pollId, array $subject): ?array {
		foreach ($this->forSubjectRaw(subject: $subject) as $poll) {
			if ((string)($poll['uuid'] ?? $poll['id'] ?? '') === $pollId) {
				return $poll;
			}
		}

		return null;
	}//end pollForSubject()

	/**
	 * The subject's own polls, without the `myResponse` annotation —
	 * `forSubject()`'s own read, factored out so `pollForSubject()` does not
	 * pay for a response lookup it does not need.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function forSubjectRaw(array $subject): array {
		$audience = (string)($subject['audience'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		if ($audience === '' || $organisation === '') {
			return [];
		}

		return $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::POLL_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			limit: 200,
			filter: ['audience' => $audience]
		);
	}//end forSubjectRaw()

	/**
	 * Whether a poll's `closesAt` is in the past.
	 *
	 * @param array<string, mixed> $poll The poll.
	 *
	 * @return bool
	 */
	private function isClosed(array $poll): bool {
		$closesAt = (string)($poll['closesAt'] ?? '');
		if ($closesAt === '') {
			return false;
		}

		try {
			$parsed = new DateTimeImmutable($closesAt);
		} catch (Exception $e) {
			// An unparsable closesAt is treated as no close date, matching
			// the empty-string case above — never a fatal error on bad data.
			return false;
		}

		return $parsed < new DateTimeImmutable();
	}//end isClosed()

	/**
	 * Whether a poll declares the given option id.
	 *
	 * @param array<string, mixed> $poll The poll.
	 * @param string $optionId The option to check.
	 *
	 * @return bool
	 */
	private function declaresOption(array $poll, string $optionId): bool {
		foreach ((array)($poll['options'] ?? []) as $option) {
			if (is_array($option) === true && (string)($option['id'] ?? '') === $optionId) {
				return true;
			}
		}

		return false;
	}//end declaresOption()

	/**
	 * The subject's own existing response to one poll, or null.
	 *
	 * @param string $pollId The poll.
	 * @param string $subjectRef The subject's own reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function existingResponse(string $pollId, string $subjectRef): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::RESPONSE_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			limit: 1,
			filter: ['pollId' => $pollId]
		);

		return ($rows[0] ?? null);
	}//end existingResponse()

	/**
	 * Every one of the subject's own responses, keyed by pollId, for the
	 * `myResponse` annotation on a list of polls.
	 *
	 * @param string $subjectRef The subject's own reference.
	 *
	 * @return array<string, string>
	 */
	private function responsesBySubject(string $subjectRef): array {
		if ($subjectRef === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::RESPONSE_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			limit: 200
		);

		$byPoll = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$byPoll[(string)($row['pollId'] ?? '')] = (string)($row['optionId'] ?? '');
			}
		}

		return $byPoll;
	}//end responsesBySubject()

	/**
	 * The row's own id, however it is carried.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function rowId(array $row): string {
		$id = (string)($row['uuid'] ?? $row['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		$self = (array)($row['@self'] ?? []);
		return (string)($self['uuid'] ?? $self['id'] ?? '');
	}//end rowId()
}//end class
