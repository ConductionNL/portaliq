<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventRsvpEligibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Who may answer an event, read from its `rsvpBy`.
 *
 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-pupil-may-answer-an-event-for-herself-with-the-number-of-seats
 */
#[CoversClass(EventRsvpEligibility::class)]
class EventRsvpAnswerersTest extends TestCase {
	/**
	 * Without `rsvpBy` guardians answer alone; non-strings are dropped.
	 *
	 * @return void
	 */
	public function testAnswerersDefaultToGuardians(): void {
		$this->assertSame(['guardian'], EventRsvpEligibility::answerersOf(event: []));
		$this->assertSame(['guardian'], EventRsvpEligibility::answerersOf(event: ['rsvpBy' => 'learner']));
		$this->assertSame(['guardian'], EventRsvpEligibility::answerersOf(event: ['rsvpBy' => [1, null]]));
		$this->assertSame(['guardian', 'learner'], EventRsvpEligibility::answerersOf(event: ['rsvpBy' => ['guardian', 7, 'learner']]));
	}//end testAnswerersDefaultToGuardians()

	/**
	 * A pupil answers for herself only when RSVP is on and learners may answer.
	 *
	 * @return void
	 */
	public function testLearnersMayAnswerNeedsRsvpOnAndLearnerListed(): void {
		$this->assertTrue(EventRsvpEligibility::learnersMayAnswer(event: ['rsvpEnabled' => true, 'rsvpBy' => ['learner']]));
		$this->assertFalse(EventRsvpEligibility::learnersMayAnswer(event: ['rsvpEnabled' => false, 'rsvpBy' => ['learner']]));
		$this->assertFalse(EventRsvpEligibility::learnersMayAnswer(event: ['rsvpEnabled' => 'yes', 'rsvpBy' => ['learner']]));
		$this->assertFalse(EventRsvpEligibility::learnersMayAnswer(event: ['rsvpEnabled' => true]));
		$this->assertFalse(EventRsvpEligibility::learnersMayAnswer(event: ['rsvpEnabled' => true, 'rsvpBy' => ['guardian']]));
	}//end testLearnersMayAnswerNeedsRsvpOnAndLearnerListed()
}//end class
