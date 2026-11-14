<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\MandateParties;
use PHPUnit\Framework\TestCase;

/**
 * A mandate party is a typed string: `kvk:<8 digits>` for an eHerkenning
 * company, `subject:<ref>` for a person. The type prefix is what every later
 * comparison keys on, so a bare value is given one and a value that already
 * carries one is left alone.
 *
 * @covers \OCA\Portaliq\Service\Identity\MandateParties
 *
 * @spec openspec/specs/portal-identity-space/spec.md
 */
class MandatePartiesTest extends TestCase {

	private MandateParties $parties;

	protected function setUp(): void {
		$this->parties = new MandateParties();

	}//end setUp()

	public function testAnEherkenningSubjectIsTheCompanyWhenItsKvkIsEightDigits(): void {
		$this->assertSame(
			'kvk:12345678',
			$this->parties->partyOf(['provider' => 'eherkenning', 'kvk' => '12345678'])
		);

	}//end testAnEherkenningSubjectIsTheCompanyWhenItsKvkIsEightDigits()

	public function testAnEherkenningSubjectWithoutAValidKvkIsNoParty(): void {
		$this->assertNull($this->parties->partyOf(['provider' => 'eherkenning', 'kvk' => '123']));
		$this->assertNull($this->parties->partyOf(['provider' => 'eherkenning']));

	}//end testAnEherkenningSubjectWithoutAValidKvkIsNoParty()

	public function testAnyOtherSubjectIsThePersonByReference(): void {
		$this->assertSame('subject:ref-1', $this->parties->partyOf(['subjectRef' => 'ref-1']));
		$this->assertNull($this->parties->partyOf(['subjectRef' => '']));

	}//end testAnyOtherSubjectIsThePersonByReference()

	public function testHoldersOfGivesBothThePersonAndTheCompanyForAnEherkenningSubject(): void {
		$holders = $this->parties->holdersOf([
			'provider' => 'eherkenning',
			'subjectRef' => 'ref-1',
			'kvk' => '12345678',
		]);

		$this->assertSame(['subject:ref-1', 'kvk:12345678'], $holders);

	}//end testHoldersOfGivesBothThePersonAndTheCompanyForAnEherkenningSubject()

	public function testHoldersOfGivesOnlyThePersonForANonCompanySubject(): void {
		$this->assertSame(['subject:ref-1'], $this->parties->holdersOf(['subjectRef' => 'ref-1']));
		// A non-eHerkenning subject never contributes a kvk holder.
		$this->assertSame(['subject:ref-1'], $this->parties->holdersOf(['subjectRef' => 'ref-1', 'kvk' => '12345678']));
		$this->assertSame([], $this->parties->holdersOf([]));

	}//end testHoldersOfGivesOnlyThePersonForANonCompanySubject()

	public function testTypedLeavesAnAlreadyTypedOrEmptyValueAlone(): void {
		$this->assertSame('', $this->parties->typed('   '));
		$this->assertSame('kvk:12345678', $this->parties->typed('kvk:12345678'));
		$this->assertSame('subject:ref-1', $this->parties->typed('subject:ref-1'));

	}//end testTypedLeavesAnAlreadyTypedOrEmptyValueAlone()

	public function testTypedGivesABareValueTheRightPrefix(): void {
		$this->assertSame('kvk:12345678', $this->parties->typed(' 12345678 '));
		// Anything that is not eight digits is treated as a subject reference.
		$this->assertSame('subject:ref-1', $this->parties->typed('ref-1'));
		$this->assertSame('subject:1234567', $this->parties->typed('1234567'));

	}//end testTypedGivesABareValueTheRightPrefix()

	public function testHolderOfTypesAStoredHolderAndFallsBackToTheSubjectReference(): void {
		$this->assertSame('kvk:12345678', $this->parties->holderOf(['holder' => '12345678']));
		$this->assertSame('subject:ref-1', $this->parties->holderOf(['holder' => 'subject:ref-1']));
		$this->assertSame('subject:ref-2', $this->parties->holderOf(['subjectRef' => 'ref-2']));
		$this->assertSame('', $this->parties->holderOf([]));

	}//end testHolderOfTypesAStoredHolderAndFallsBackToTheSubjectReference()
}//end class
