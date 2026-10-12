<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\IntakeDrain;
use OCA\Portaliq\Service\Intake\IntakeQueueDrain;
use OCA\Portaliq\Service\Intake\LandingSubmissionDrain;
use OCA\Portaliq\Service\Intake\PortalDraftDrain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * submit-creates-the-case-directly 4.1: `occ portaliq:intake:drain` reports
 * and exits 0 only when nothing waits any more, so the removal (4.3) can
 * follow a zero.
 *
 * @covers \OCA\Portaliq\Command\IntakeDrain
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */
class IntakeDrainTest extends TestCase {

	public function testAnEmptyQueueExitsZero(): void {
		$drain = $this->createMock(IntakeQueueDrain::class);
		$drain->expects($this->once())->method('drain')->with(200)->willReturn(['delivered' => 3, 'refused' => 0, 'waiting' => 0, 'reasons' => []]);

		$tester = new CommandTester(new IntakeDrain($drain));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('Delivered: 3', $tester->getDisplay());
		$this->assertStringContainsString('Still waiting: 0', $tester->getDisplay());
	}//end testAnEmptyQueueExitsZero()

	public function testARefusalIsNamedAndTheRunExitsOne(): void {
		$drain = $this->createMock(IntakeQueueDrain::class);
		$drain->method('drain')->with(5)->willReturn(['delivered' => 0, 'refused' => 1, 'waiting' => 1, 'reasons' => ['AANVRAAG-X' => 'The form this request came from is no longer published.']]);

		$tester = new CommandTester(new IntakeDrain($drain));

		$this->assertSame(1, $tester->execute(['--limit' => '5']));
		$this->assertStringContainsString('AANVRAAG-X: The form this request came from is no longer published.', $tester->getDisplay());
	}//end testARefusalIsNamedAndTheRunExitsOne()

	public function testADryRunOnlyCountsAndSendsNothing(): void {
		$drain = $this->createMock(IntakeQueueDrain::class);
		$drain->expects($this->never())->method('drain');
		$drain->method('waiting')->willReturnOnConsecutiveCalls(4, 0);

		$tester = new CommandTester(new IntakeDrain($drain));

		$this->assertSame(1, $tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Waiting: 4', $tester->getDisplay());
		$this->assertSame(0, $tester->execute(['--dry-run' => true]));
	}//end testADryRunOnlyCountsAndSendsNothing()
	public function testSavedDraftsAreMovedAndCountTowardsTheZero(): void {
		$drain = $this->createMock(IntakeQueueDrain::class);
		$drain->method('drain')->willReturn(['delivered' => 0, 'refused' => 0, 'waiting' => 0, 'reasons' => []]);
		$drain->method('waiting')->willReturn(0);
		$drafts = $this->createMock(PortalDraftDrain::class);
		$drafts->method('drain')->willReturnOnConsecutiveCalls(
			['converted' => 2, 'expired' => 1, 'refused' => 1, 'reasons' => ['r-1' => 'the destination refused it (422): Not a date.'], 'waiting' => 1],
			['converted' => 0, 'expired' => 0, 'refused' => 0, 'reasons' => [], 'waiting' => 0]
		);
		$drafts->method('waiting')->willReturn(3);

		$tester = new CommandTester(new IntakeDrain($drain, $drafts));

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('Drafts moved: 2', $tester->getDisplay());
		$this->assertStringContainsString('r-1: the destination refused it (422): Not a date.', $tester->getDisplay());
		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(1, $tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Drafts waiting: 3', $tester->getDisplay());
	}//end testSavedDraftsAreMovedAndCountTowardsTheZero()
	public function testStoredLandingSubmissionsAreDeliveredAndCountTowardsTheZero(): void {
		$drain = $this->createMock(IntakeQueueDrain::class);
		$drain->method('drain')->willReturn(['delivered' => 0, 'refused' => 0, 'waiting' => 0, 'reasons' => []]);
		$landing = $this->createMock(LandingSubmissionDrain::class);
		$landing->method('drain')->willReturn(['delivered' => 4, 'refused' => 1, 'reasons' => ['s-9' => 'its form names no destination'], 'waiting' => 1]);
		$landing->method('waiting')->willReturn(1);

		$tester = new CommandTester(new IntakeDrain($drain, null, $landing));

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('Landing-page submissions delivered: 4', $tester->getDisplay());
		$this->assertStringContainsString('s-9: its form names no destination', $tester->getDisplay());
		$this->assertSame(1, $tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Landing-page submissions waiting: 1', $tester->getDisplay());
	}//end testStoredLandingSubmissionsAreDeliveredAndCountTowardsTheZero()
}//end class
