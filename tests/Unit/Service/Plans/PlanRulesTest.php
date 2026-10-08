<?php

/**
 * Portaliq Plan Rules Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Plans\PlanRules;
use PHPUnit\Framework\TestCase;

/**
 * The dates and counts of a plan: REQ-SPL-002 and REQ-SPL-004.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PlanRulesTest extends TestCase {

	/**
	 * A template becomes an end date and actions with end dates, from the start day.
	 *
	 * @return void
	 */
	public function testATemplateBecomesDatesFromTheStartDay(): void {
		$template = ['durationDays' => 56, 'actions' => [
			['title' => 'Brieven verzamelen', 'kind' => 'once', 'offsetDays' => 7],
			['title' => ' Budget bijhouden ', 'kind' => 'recurring', 'offsetDays' => 28],
			['title' => '', 'offsetDays' => 1],
			['kind' => 'once'],
			'text',
			['title' => 'Zonder offset'],
			['title' => 'Terug in de tijd', 'offsetDays' => -5],
		]];
		$out = PlanRules::expand(template: $template, today: '2026-09-01');
		$this->assertSame('2026-10-27', $out['endDate'], '1 September plus 56 days');
		$this->assertSame(
			[
				['title' => 'Brieven verzamelen', 'kind' => 'once', 'endDate' => '2026-09-08'],
				['title' => 'Budget bijhouden', 'kind' => 'recurring', 'endDate' => '2026-09-29'],
				['title' => 'Zonder offset', 'kind' => 'once', 'endDate' => '2026-09-01'],
				['title' => 'Terug in de tijd', 'kind' => 'once', 'endDate' => '2026-09-01'],
			],
			$out['actions']
		);
		$this->assertSame('2026-02-28', PlanRules::expand(template: ['durationDays' => 0], today: '2026-01-03')['endDate'], 'no duration: 56 days');
		$many = ['actions' => array_fill(0, 30, ['title' => 'x'])];
		$this->assertCount(PlanRules::MAX_TEMPLATE_ACTIONS, PlanRules::expand(template: $many, today: '2026-09-01')['actions']);
	}//end testATemplateBecomesDatesFromTheStartDay()

	/**
	 * Days left count whole days, negative after the end, null for no date.
	 *
	 * @return void
	 */
	public function testDaysLeft(): void {
		$this->assertSame(12, PlanRules::daysLeft(endDate: '2026-10-20', today: '2026-10-08'));
		$this->assertSame(0, PlanRules::daysLeft(endDate: '2026-10-08T23:00:00+02:00', today: '2026-10-08'));
		$this->assertSame(-3, PlanRules::daysLeft(endDate: '2026-10-05', today: '2026-10-08'));
		$this->assertNull(PlanRules::daysLeft(endDate: '', today: '2026-10-08'));
		$this->assertNull(PlanRules::daysLeft(endDate: '2026-10-20', today: 'today'));
	}//end testDaysLeft()

	/**
	 * A running plan with open actions asks for action in its last 14 days.
	 *
	 * @return void
	 */
	public function testAPlanAsksForActionInItsLastFourteenDays(): void {
		$plan = ['status' => 'running', 'endDate' => '2026-10-20'];
		$this->assertTrue(PlanRules::needsAction(plan: $plan, openActions: 3, today: '2026-10-08'), 'twelve days left');
		$this->assertTrue(PlanRules::needsAction(plan: $plan, openActions: 1, today: '2026-10-06'), 'exactly fourteen');
		$this->assertFalse(PlanRules::needsAction(plan: $plan, openActions: 1, today: '2026-10-05'), 'fifteen');
		$this->assertFalse(PlanRules::needsAction(plan: $plan, openActions: 0, today: '2026-10-08'), 'nothing open');
		$this->assertFalse(PlanRules::needsAction(plan: $plan, openActions: 3, today: '2026-10-21'), 'past the end');
		$this->assertFalse(PlanRules::needsAction(plan: ['status' => 'done'] + $plan, openActions: 3, today: '2026-10-08'));
		$this->assertFalse(PlanRules::needsAction(plan: ['status' => 'running'], openActions: 3, today: '2026-10-08'), 'no end date');
		$this->assertSame('action', PlanRules::stateOf(plan: $plan, openActions: 3, today: '2026-10-08'));
		$this->assertSame('running', PlanRules::stateOf(plan: $plan, openActions: 3, today: '2026-09-01'));
		$this->assertSame('done', PlanRules::stateOf(plan: ['status' => 'done'] + $plan, openActions: 3, today: '2026-10-08'));
	}//end testAPlanAsksForActionInItsLastFourteenDays()

	/**
	 * One reminder per end date: a sent mark stops it, and clearing the mark allows the next.
	 *
	 * @return void
	 */
	public function testOneReminderPerEndDate(): void {
		$plan = ['status' => 'running', 'endDate' => '2026-10-20'];
		$this->assertTrue(PlanRules::reminderDue(plan: $plan, openActions: 3, today: '2026-10-08'));
		$sent = $plan + ['endReminderSentAt' => '2026-10-08T07:00:00+00:00'];
		$this->assertFalse(PlanRules::reminderDue(plan: $sent, openActions: 3, today: '2026-10-09'));
		$this->assertTrue(PlanRules::reminderDue(plan: ['endDate' => '2026-11-01', 'endReminderSentAt' => null] + $sent, openActions: 3, today: '2026-10-20'), 'a new end date, a cleared mark');
	}//end testOneReminderPerEndDate()

	/**
	 * A done plan stays a year after it was done.
	 *
	 * @return void
	 */
	public function testADonePlanStaysAYear(): void {
		$done = ['status' => 'done', 'doneAt' => '2026-01-10T12:00:00+01:00'];
		$this->assertTrue(PlanRules::visible(plan: $done, today: '2027-01-10'));
		$this->assertFalse(PlanRules::visible(plan: $done, today: '2027-01-11'));
		$this->assertTrue(PlanRules::visible(plan: ['status' => 'running'], today: '2030-01-01'));
		$this->assertTrue(PlanRules::visible(plan: ['status' => 'done'], today: '2030-01-01'), 'no done date: kept');
	}//end testADonePlanStaysAYear()
}//end class
