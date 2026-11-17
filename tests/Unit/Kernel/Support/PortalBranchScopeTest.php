<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Kernel\Support;

use OCA\Portaliq\Kernel\Support\PortalBranchScope;
use PHPUnit\Framework\TestCase;

/**
 * A business visitor acting for one branch sees and writes only that
 * branch's rows; without a branch nothing is filtered or stamped.
 *
 * @covers \OCA\Portaliq\Kernel\Support\PortalBranchScope
 * @uses   \OCA\Portaliq\Service\Branch\BranchNumber
 */
class PortalBranchScopeTest extends TestCase {
	private const BRANCH = '000012345678';

	private const OTHER = '000087654321';

	public function testNormaliseKeepsANamedBranchFieldThatIsOneOfTheFields(): void {
		$scope = new PortalBranchScope();

		$this->assertSame(['id' => 'a'], $scope->normalise(collection: ['id' => 'a']));
		$this->assertSame(
			['branchField' => 'vestiging', 'fields' => ['naam', 'vestiging']],
			$scope->normalise(collection: ['branchField' => 'vestiging', 'fields' => ['naam', 'vestiging']])
		);
		$this->assertSame(['branchField' => 'vestiging'], $scope->normalise(collection: ['branchField' => 'vestiging']));
		$this->assertSame(['branchField' => 'vestiging', 'fields' => 'all'], $scope->normalise(collection: ['branchField' => 'vestiging', 'fields' => 'all']));
	}//end testNormaliseKeepsANamedBranchFieldThatIsOneOfTheFields()

	public function testNormaliseDropsAnUnnamedOrUnlistedBranchField(): void {
		$scope = new PortalBranchScope();

		$this->assertSame(['fields' => ['naam']], $scope->normalise(collection: ['branchField' => 'vestiging', 'fields' => ['naam']]));
		$this->assertSame([], $scope->normalise(collection: ['branchField' => '']));
		$this->assertSame([], $scope->normalise(collection: ['branchField' => null]));
		$this->assertSame(['fields' => [7]], $scope->normalise(collection: ['branchField' => 7, 'fields' => [7]]));
	}//end testNormaliseDropsAnUnnamedOrUnlistedBranchField()

	public function testRowsKeepOnlyTheVisitorsBranchAndReindex(): void {
		$rows = [
			['id' => 1, 'vestiging' => self::OTHER],
			['id' => 2, 'vestiging' => self::BRANCH],
			'not a row',
			['id' => 3],
			['id' => 4, 'vestiging' => ['nr' => self::BRANCH]],
			['id' => 5, 'vestiging' => (int)self::BRANCH],
			['id' => 6, 'vestiging' => self::BRANCH],
		];

		$kept = (new PortalBranchScope())->rows(
			subject: ['branch' => self::BRANCH],
			collection: ['branchField' => 'vestiging'],
			rows: $rows
		);

		$this->assertSame([['id' => 2, 'vestiging' => self::BRANCH], ['id' => 6, 'vestiging' => self::BRANCH]], $kept);
	}//end testRowsKeepOnlyTheVisitorsBranchAndReindex()

	public function testWithoutAValidBranchEveryRowIsKeptAsGiven(): void {
		$rows = [3 => ['vestiging' => self::OTHER], 'x' => 'not a row'];
		$scope = new PortalBranchScope();

		$this->assertSame($rows, $scope->rows(subject: [], collection: ['branchField' => 'vestiging'], rows: $rows));
		$this->assertSame($rows, $scope->rows(subject: ['branch' => '12345'], collection: ['branchField' => 'vestiging'], rows: $rows));
		$this->assertSame($rows, $scope->rows(subject: ['branch' => (int)self::BRANCH], collection: ['branchField' => 'vestiging'], rows: $rows));
		$this->assertTrue($scope->admits(subject: ['branch' => self::BRANCH . "\n"], collection: ['branchField' => 'vestiging'], row: []));
	}//end testWithoutAValidBranchEveryRowIsKeptAsGiven()

	public function testACollectionWithoutBranchFieldShowsNothingToARestrictedLogin(): void {
		$scope = new PortalBranchScope();
		$row = ['vestiging' => self::OTHER];

		$this->assertFalse($scope->admits(subject: ['branch' => self::BRANCH, 'branchRestricted' => true], collection: [], row: $row));
		$this->assertTrue($scope->admits(subject: ['branch' => self::BRANCH], collection: [], row: $row));
		$this->assertTrue($scope->admits(subject: ['branch' => self::BRANCH, 'branchRestricted' => 'yes'], collection: ['branchField' => ''], row: $row));
		$this->assertSame(
			[],
			$scope->rows(subject: ['branch' => self::BRANCH, 'branchRestricted' => true], collection: [], rows: [$row])
		);
	}//end testACollectionWithoutBranchFieldShowsNothingToARestrictedLogin()

	public function testStampWritesTheVisitorsBranchOverWhatWasSent(): void {
		$scope = new PortalBranchScope();

		$this->assertSame(
			['naam' => 'x', 'vestiging' => self::BRANCH],
			$scope->stamp(subject: ['branch' => self::BRANCH], action: ['branchField' => 'vestiging'], data: ['vestiging' => self::OTHER, 'naam' => 'x'])
		);
	}//end testStampWritesTheVisitorsBranchOverWhatWasSent()

	public function testStampWithoutBranchRemovesASentBranchAndWithoutFieldLeavesDataAlone(): void {
		$scope = new PortalBranchScope();
		$data = ['vestiging' => self::OTHER, 'naam' => 'x'];

		$this->assertSame(['naam' => 'x'], $scope->stamp(subject: [], action: ['branchField' => 'vestiging'], data: $data));
		$this->assertSame($data, $scope->stamp(subject: ['branch' => self::BRANCH], action: [], data: $data));
		$this->assertSame($data, $scope->stamp(subject: ['branch' => self::BRANCH], action: ['branchField' => ''], data: $data));
		$this->assertSame($data, $scope->stamp(subject: ['branch' => self::BRANCH], action: ['branchField' => ['vestiging']], data: $data));
	}//end testStampWithoutBranchRemovesASentBranchAndWithoutFieldLeavesDataAlone()
}//end class
