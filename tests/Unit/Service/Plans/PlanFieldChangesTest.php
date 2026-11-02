<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Plans\PlanFieldChanges;
use OCA\Portaliq\Service\Plans\PlanRules;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validating a single-field edit of a shared plan.
 */
#[CoversClass(PlanFieldChanges::class)]
#[UsesClass(PlanRules::class)]
class PlanFieldChangesTest extends TestCase {
	/**
	 * Only the owner may change title, end date and status.
	 *
	 * @return void
	 */
	public function testOwnerOnlyFields(): void {
		$c = new PlanFieldChanges();

		foreach (['title', 'endDate', 'status'] as $field) {
			$this->assertSame(PortalPlanService::FORBIDDEN, $c->change([], 'u', false, $field, 'x', '2026-05-01')['status']);
		}
	}//end testOwnerOnlyFields()

	/**
	 * Goals, titles and notes are bounded strings.
	 *
	 * @return void
	 */
	public function testTextFields(): void {
		$c = new PlanFieldChanges();

		$this->assertSame(['goal' => 'x'], $c->change([], 'u', false, 'goal', ' x ', '2026-05-01')['data']);
		$this->assertSame(['goalDetail' => 'y'], $c->change([], 'u', true, 'goalDetail', 'y', '2026-05-01')['data']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', false, 'goal', 5, '2026-05-01')['status']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', false, 'goal', str_repeat('a', 2001), '2026-05-01')['status']);

		$this->assertSame(['title' => 'T'], $c->change([], 'u', true, 'title', ' T ', '2026-05-01')['data']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'title', '  ', '2026-05-01')['status']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'title', str_repeat('a', 201), '2026-05-01')['status']);

		$note = $c->change([], 'jan', false, 'note', ' hello ', '2026-05-01');
		$this->assertSame(PortalPlanService::OK, $note['status']);
		$this->assertSame('hello', $note['data']['note']['text']);
		$this->assertSame('jan', $note['data']['note']['editedBy']);
		$this->assertNotEmpty($note['data']['note']['editedAt']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', false, 'note', str_repeat('a', 5001), '2026-05-01')['status']);
	}//end testTextFields()

	/**
	 * A changed end date resets the reminder; an unchanged one does not.
	 *
	 * @return void
	 */
	public function testEndDate(): void {
		$c = new PlanFieldChanges();

		$moved = $c->change(['endDate' => '2026-06-01T00:00:00+00:00'], 'u', true, 'endDate', '2026-07-01', '2026-05-01');
		$this->assertSame(['endDate' => '2026-07-01', 'endReminderSentAt' => null], $moved['data']);

		$same = $c->change(['endDate' => '2026-07-01T10:00:00+00:00'], 'u', true, 'endDate', '2026-07-01', '2026-05-01');
		$this->assertSame(['endDate' => '2026-07-01'], $same['data']);

		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'endDate', 'soon', '2026-05-01')['status']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'endDate', '2026-07-01T10:00', '2026-05-01')['status']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'endDate', 20260701, '2026-05-01')['status']);
	}//end testEndDate()

	/**
	 * Status can only move to done; unknown fields are invalid.
	 *
	 * @return void
	 */
	public function testStatusAndUnknown(): void {
		$c = new PlanFieldChanges();

		$done = $c->change([], 'u', true, 'status', 'done', '2026-05-01');
		$this->assertSame('done', $done['data']['status']);
		$this->assertArrayHasKey('doneAt', $done['data']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'status', 'open', '2026-05-01')['status']);
		$this->assertSame(PortalPlanService::INVALID, $c->change([], 'u', true, 'whatever', 'x', '2026-05-01')['status']);
	}//end testStatusAndUnknown()
}//end class
