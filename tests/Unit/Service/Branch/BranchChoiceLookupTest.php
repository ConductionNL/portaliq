<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Branch;

use OCA\Portaliq\Service\Branch\BranchChoice;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which account the branch list is looked up for, and when no lookup is
 * made at all.
 *
 * @covers \OCA\Portaliq\Service\Branch\BranchChoice
 */
class BranchChoiceLookupTest extends TestCase {
	private const BRANCHES = [['number' => '000012345678', 'name' => 'Hoofdvestiging']];

	private PortalAccountLookup&MockObject $accounts;

	private PortalRegisteredDetailsService&MockObject $details;

	protected function setUp(): void {
		$this->accounts = $this->getMockBuilder(PortalAccountLookup::class)->disableOriginalConstructor()->onlyMethods(['bySubjectRef'])->getMock();
		$this->details  = $this->getMockBuilder(PortalRegisteredDetailsService::class)->disableOriginalConstructor()->onlyMethods(['companyBranches'])->getMock();
	}//end setUp()

	public function testTheAccountOfTheSubjectRefIsLookedUpAndItsKvkNumberTrimmed(): void {
		$this->accounts->expects($this->once())->method('bySubjectRef')->with('ref-1')
			->willReturn(['identityType' => 'eherkenning', 'identityRef' => " 12345678\n"]);
		$this->details->expects($this->once())->method('companyBranches')->with('12345678')->willReturn(self::BRANCHES);

		$this->assertSame(self::BRANCHES, $this->choice()->branchesFor(subject: ['subjectRef' => 'ref-1']));
	}//end testTheAccountOfTheSubjectRefIsLookedUpAndItsKvkNumberTrimmed()

	public function testWithoutSubjectRefNoAccountIsLookedUp(): void {
		$this->accounts->expects($this->never())->method('bySubjectRef');
		$this->details->expects($this->never())->method('companyBranches');

		$this->assertSame([], $this->choice()->branchesFor(subject: []));
		$this->assertSame([], $this->choice()->branchesFor(subject: ['subjectRef' => '']));
	}//end testWithoutSubjectRefNoAccountIsLookedUp()

	public function testAnUnknownAccountOrOneWithoutIdentityTypeIsOfferedNothing(): void {
		$this->accounts->method('bySubjectRef')->willReturnOnConsecutiveCalls(null, ['identityRef' => '12345678']);
		$this->details->expects($this->never())->method('companyBranches');

		$this->assertSame([], $this->choice()->branchesFor(subject: ['subjectRef' => 'ref-1']));
		$this->assertSame([], $this->choice()->branchesFor(subject: ['subjectRef' => 'ref-1']));
	}//end testAnUnknownAccountOrOneWithoutIdentityTypeIsOfferedNothing()

	public function testOnlyBooleanTrueRestrictsTheSession(): void {
		$this->accounts->method('bySubjectRef')->willReturn(['identityType' => 'eherkenning', 'identityRef' => '12345678']);
		$this->details->method('companyBranches')->willReturn(self::BRANCHES);

		$this->assertSame(self::BRANCHES, $this->choice()->branchesFor(subject: ['subjectRef' => 'ref-1', 'branchRestricted' => 'true']));
		$this->assertTrue($this->choice()->allows(subject: ['subjectRef' => 'ref-1', 'branchRestricted' => 1], branch: '000012345678'));
	}//end testOnlyBooleanTrueRestrictsTheSession()

	public function testTheWholeCompanyNeedsNoLookup(): void {
		$this->accounts->expects($this->never())->method('bySubjectRef');

		$this->assertTrue($this->choice()->allows(subject: ['subjectRef' => 'ref-1'], branch: ''));
	}//end testTheWholeCompanyNeedsNoLookup()

	public function testABranchMatchesTheListedNumberExactly(): void {
		$this->accounts->method('bySubjectRef')->willReturn(['identityType' => 'eherkenning', 'identityRef' => '12345678']);
		$this->details->method('companyBranches')->willReturn([['number' => '000012345678'], ['name' => 'Geen nummer']]);

		$this->assertTrue($this->choice()->allows(subject: ['subjectRef' => 'ref-1'], branch: '000012345678'));
		$this->assertFalse($this->choice()->allows(subject: ['subjectRef' => 'ref-1'], branch: '12345678'));
		$this->assertFalse($this->choice()->allows(subject: ['subjectRef' => 'ref-1'], branch: '000012345678 '));
	}//end testABranchMatchesTheListedNumberExactly()

	/**
	 * The choice over the mocked account lookup and registered details.
	 *
	 * @return BranchChoice
	 */
	private function choice(): BranchChoice {
		return new BranchChoice($this->accounts, $this->details);
	}//end choice()
}//end class
