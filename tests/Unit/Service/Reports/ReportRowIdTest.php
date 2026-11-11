<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Reports;

use OCA\Portaliq\Service\Reports\ReportRowId;
use PHPUnit\Framework\TestCase;

/**
 * The row identifier the report services read: own uuid, own id, then the
 * same under `@self`, skipping empty and non-scalar values.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportRowIdTest extends TestCase {

	/**
	 * Each candidate in turn, and none.
	 *
	 * @return void
	 */
	public function testTheFirstUsableCandidateWins(): void {
		$this->assertSame('u', ReportRowId::of(['uuid' => 'u', 'id' => 'i', '@self' => ['uuid' => 's']]));
		$this->assertSame('i', ReportRowId::of(['uuid' => '', 'id' => 'i']));
		$this->assertSame('7', ReportRowId::of(['id' => 7]));
		$this->assertSame('s', ReportRowId::of(['uuid' => ['x'], 'id' => 1.5, '@self' => ['uuid' => 's', 'id' => 't']]));
		$this->assertSame('t', ReportRowId::of(['@self' => ['uuid' => '', 'id' => 't']]));
		$this->assertSame('', ReportRowId::of(['uuid' => '', '@self' => ['id' => null]]));
		$this->assertSame('', ReportRowId::of([]));

	}//end testTheFirstUsableCandidateWins()

}//end class
