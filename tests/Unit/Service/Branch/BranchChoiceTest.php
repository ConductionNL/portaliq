<?php

/**
 * Tests for BranchChoice (signin-eherkenning-branch T05, T06).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Branch;

use OCA\Portaliq\Service\Branch\BranchChoice;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;

class BranchChoiceTest extends TestCase {

	private const BRANCHES = [
		['number' => '000012345678', 'name' => 'Bakkerij de Korenschoof', 'address' => 'Marktplein 1, 3511AB Utrecht', 'main' => true],
		['number' => '000087654321', 'name' => 'Korenschoof Zuid', 'address' => 'Laan 40, 3521CD Utrecht', 'main' => false],
	];

	private const WHOLE_COMPANY = ['subjectRef' => 's1', 'branch' => '', 'branchRestricted' => false];

	public function testAWholeCompanySessionIsOfferedTheCompanysBranches(): void {
		$choice = $this->choice(['identityType' => 'eherkenning', 'identityRef' => '12345678'], self::BRANCHES, '12345678');

		$this->assertSame(self::BRANCHES, $choice->branchesFor(self::WHOLE_COMPANY));

	}//end testAWholeCompanySessionIsOfferedTheCompanysBranches()

	public function testOnlyTheCompanysOwnBranchOrTheWholeCompanyIsAllowed(): void {
		$choice = $this->choice(['identityType' => 'eherkenning', 'identityRef' => '12345678'], self::BRANCHES, '12345678');

		$this->assertTrue($choice->allows(self::WHOLE_COMPANY, '000087654321'));
		$this->assertTrue($choice->allows(self::WHOLE_COMPANY, ''));
		$this->assertFalse($choice->allows(self::WHOLE_COMPANY, '000099999999'), 'a foreign branch is refused');

	}//end testOnlyTheCompanysOwnBranchOrTheWholeCompanyIsAllowed()

	public function testARestrictedSessionIsOfferedNothingAndAllowedNothing(): void {
		$choice = $this->choice(['identityType' => 'eherkenning', 'identityRef' => '12345678'], self::BRANCHES, '12345678');
		$restricted = ['subjectRef' => 's1', 'branch' => '000012345678', 'branchRestricted' => true];

		$this->assertSame([], $choice->branchesFor($restricted));
		$this->assertFalse($choice->allows($restricted, '000087654321'));
		$this->assertFalse($choice->allows($restricted, ''));

	}//end testARestrictedSessionIsOfferedNothingAndAllowedNothing()

	public function testAPersonOrAnUnreadableCompanyIsOfferedNoBranch(): void {
		$person = $this->choice(['identityType' => 'digid', 'identityRef' => '999993653'], self::BRANCHES, '');
		$this->assertSame([], $person->branchesFor(self::WHOLE_COMPANY));
		$this->assertFalse($person->allows(self::WHOLE_COMPANY, '000087654321'));

		$unavailable = $this->choice(['identityType' => 'eherkenning', 'identityRef' => '12345678'], null, '12345678');
		$this->assertSame([], $unavailable->branchesFor(self::WHOLE_COMPANY));
		$this->assertFalse($unavailable->allows(self::WHOLE_COMPANY, '000087654321'), 'no list, no branch: fail closed');

	}//end testAPersonOrAnUnreadableCompanyIsOfferedNoBranch()

	/**
	 * A choice over one account and one KvK answer.
	 *
	 * @param array<string, mixed>                  $account  The caller's account.
	 * @param array<int, array<string, mixed>>|null $branches The KvK branches, null when unreadable.
	 * @param string                                $kvk      The number the KvK lookup must be asked for, or '' for never.
	 *
	 * @return BranchChoice
	 */
	private function choice(array $account, ?array $branches, string $kvk): BranchChoice {
		$accounts = $this->getMockBuilder(PortalAccountService::class)->disableOriginalConstructor()->onlyMethods(['findBySubjectRef'])->getMock();
		$accounts->method('findBySubjectRef')->willReturn($account);

		$details = $this->getMockBuilder(PortalRegisteredDetailsService::class)->disableOriginalConstructor()->onlyMethods(['companyBranches'])->getMock();
		if ($kvk === '') {
			$details->expects($this->never())->method('companyBranches');
		} else {
			$details->method('companyBranches')->with($kvk)->willReturn($branches);
		}

		return new BranchChoice($accounts, $details);
	}//end choice()
}//end class
