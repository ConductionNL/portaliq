<?php

/**
 * Contract of PollService
 *
 * The public surface of PollService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of PollService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PollServiceInterface {

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
	 * @spec openspec/specs/parent-polls/spec.md#requirement-a-poll-is-created-by-staff-addressed-to-one-audience-and-organisation
	 */
	public function create(
		string $question,
		array $options,
		string $audience,
		string $organisation,
		string $closesAt = '',
		string $createdBy = '',
	): array;

	/**
	 * Every poll for the subject's own audience and organisation, each
	 * carrying only the subject's own response.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/parent-polls/spec.md#requirement-a-portal-subject-sees-only-polls-for-their-own-audience-and-organisation
	 */
	public function forSubject(array $subject): array;

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
	 * @spec openspec/specs/parent-polls/spec.md#requirement-a-subject-may-answer-once-and-change-their-answer-while-the-poll-is-open
	 */
	public function respond(string $pollId, string $optionId, array $subject): array;
}//end interface
