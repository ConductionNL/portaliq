<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentStore;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentWayIn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The portal, account and sign-in way the example resident uses, and taking
 * a sign-in way back off the portal.
 */
#[CoversClass(ExampleResidentWayIn::class)]
class ExampleResidentWayInTest extends TestCase {

	private ExampleResidentStore&MockObject $store;

	protected function setUp(): void {
		$this->store = $this->createMock(ExampleResidentStore::class);
	}//end setUp()

	private function wayIn(): ExampleResidentWayIn {
		return new ExampleResidentWayIn($this->store);
	}//end wayIn()

	public function testThePortalIsFoundBySlug(): void {
		$this->store->method('find')->with('portaliq', 'portal', [])->willReturn([['slug' => 'a'], ['slug' => 'gem', 'id' => 'p-1']]);

		$this->assertSame(['slug' => 'gem', 'id' => 'p-1'], $this->wayIn()->portal('gem'));
		$this->assertNull($this->wayIn()->portal('none'));
	}//end testThePortalIsFoundBySlug()

	public function testTheAccountIsReadAndDeletedInThePortalAccountSchema(): void {
		$this->store->expects($this->once())->method('get')->with('portaliq', 'portalAccount', 'acc-1')->willReturn(['id' => 'acc-1']);
		$this->store->expects($this->once())->method('delete')->with('portaliq', 'portalAccount', 'acc-1')->willReturn(true);

		$this->assertSame(['id' => 'acc-1'], $this->wayIn()->account('acc-1'));
		$this->assertTrue($this->wayIn()->deleteAccount('acc-1'));
	}//end testTheAccountIsReadAndDeletedInThePortalAccountSchema()

	public function testAModeIsOfferedOnlyWhenListedExactly(): void {
		$portal = ['authentication' => ['modes' => ['password', 'digid']]];

		$this->assertTrue($this->wayIn()->offered($portal, 'password'));
		$this->assertFalse($this->wayIn()->offered($portal, 'eherkenning'));
		$this->assertFalse($this->wayIn()->offered([], 'password'));
	}//end testAModeIsOfferedOnlyWhenListedExactly()

	public function testWithdrawingAModeRemovesItAndItsLabel(): void {
		$portal = ['id' => 'p-1', 'authentication' => ['modes' => ['password', 'digid'], 'modeLabels' => ['password' => 'Example', 'digid' => 'DigiD'], 'other' => 1]];
		$this->store->method('idOf')->with($portal)->willReturn('p-1');
		$this->store->expects($this->once())->method('update')->with(
			'portaliq',
			'portal',
			'p-1',
			['authentication' => ['modes' => ['digid'], 'modeLabels' => ['digid' => 'DigiD'], 'other' => 1]]
		)->willReturn(true);

		$this->assertTrue($this->wayIn()->withdraw($portal, 'password', true));
	}//end testWithdrawingAModeRemovesItAndItsLabel()

	public function testALabelTheResidentDidNotAddIsKept(): void {
		$portal = ['authentication' => ['modes' => ['password'], 'modeLabels' => ['password' => 'Theirs']]];
		$this->store->method('idOf')->willReturn('p-1');
		$this->store->expects($this->once())->method('update')->with(
			'portaliq',
			'portal',
			'p-1',
			['authentication' => ['modes' => [], 'modeLabels' => ['password' => 'Theirs']]]
		)->willReturn(false);

		$this->assertFalse($this->wayIn()->withdraw($portal, 'password', false));
	}//end testALabelTheResidentDidNotAddIsKept()

	public function testAModeThatIsNotOfferedNeedsNoWrite(): void {
		$this->store->expects($this->never())->method('update');

		$this->assertTrue($this->wayIn()->withdraw(['authentication' => ['modes' => ['digid']]], 'password', true));
	}//end testAModeThatIsNotOfferedNeedsNoWrite()
}//end class
