<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Plans\PlanActionFields;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Validating the fields of a plan action.
 */
#[CoversClass(PlanActionFields::class)]
class PlanActionFieldsTest extends TestCase {
	/**
	 * Sound input is cleaned and unknown keys dropped.
	 *
	 * @return void
	 */
	public function testCleanKeepsValidFields(): void {
		$status = PortalPlanService::STATUSES[0];
		$clean  = (new PlanActionFields())->clean(
			['title' => ' Call ', 'status' => $status, 'kind' => 'recurring', 'description' => ' d ', 'endDate' => '2026-05-01', 'assignee' => 'u1', 'junk' => 1],
			['u1', 'u2'],
			true
		);

		$this->assertSame(
			['title' => 'Call', 'status' => $status, 'kind' => 'recurring', 'description' => 'd', 'endDate' => '2026-05-01', 'assignee' => 'u1'],
			$clean
		);
	}//end testCleanKeepsValidFields()

	/**
	 * A title is required on create, optional on update, and bounded.
	 *
	 * @return void
	 */
	public function testTitleRules(): void {
		$f = new PlanActionFields();

		$this->assertNull($f->clean([], [], true));
		$this->assertNull($f->clean(['title' => '  '], [], false));
		$this->assertNull($f->clean(['title' => 5], [], false));
		$this->assertNull($f->clean(['title' => str_repeat('a', 201)], [], false));
		$this->assertSame([], $f->clean([], [], false));
	}//end testTitleRules()

	/**
	 * Any invalid field rejects the whole input.
	 *
	 * @return void
	 */
	public function testInvalidFieldsReject(): void {
		$f = new PlanActionFields();

		foreach ([['status' => 'bogus'], ['kind' => 'weekly'], ['description' => str_repeat('a', 2001)], ['description' => 5], ['endDate' => '1 May'], ['endDate' => 5], ['assignee' => 'stranger']] as $data) {
			$this->assertNull($f->clean($data, ['u1'], false));
		}
	}//end testInvalidFieldsReject()
}//end class
