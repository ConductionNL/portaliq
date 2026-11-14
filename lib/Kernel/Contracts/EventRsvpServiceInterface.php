<?php

/**
 * Contract of EventRsvpService
 *
 * The public surface of EventRsvpService that other components call, so they depend
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
 * Contract of EventRsvpService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EventRsvpServiceInterface {

	/**
	 * The refusals attempt() answers with; null is a recorded answer.
	 */
	public const REASON_NOT_FOUND = 'not_found';

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
	public function attempt(string $subjectRef, string $eventId, string $childRef, string $response, ?int $seats = null, ?string $now = null): ?string;
}//end interface
