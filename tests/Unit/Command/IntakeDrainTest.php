<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\IntakeDrain;
use OCA\Portaliq\Service\Intake\IntakeQueueDrain;
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
		$drain->expects($this->once())->method('drain')->with(200, false)->willReturn(['delivered' => 3, 'refused' => 0, 'waiting' => 0, 'reasons' => []]);

		$tester = new CommandTester(new IntakeDrain($drain));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('Delivered: 3', $tester->getDisplay());
		$this->assertStringContainsString('Still waiting: 0', $tester->getDisplay());
	}//end testAnEmptyQueueExitsZero()

	public function testARefusalIsNamedAndTheRunExitsOne(): void {
		$drain = $this->createMock(IntakeQueueDrain::class);
		$drain->method('drain')->with(5, true)->willReturn(['delivered' => 0, 'refused' => 1, 'waiting' => 1, 'reasons' => ['AANVRAAG-X' => 'The form this request came from is no longer published.']]);

		$tester = new CommandTester(new IntakeDrain($drain));

		$this->assertSame(1, $tester->execute(['--limit' => '5', '--dry-run' => true]));
		$this->assertStringContainsString('AANVRAAG-X: The form this request came from is no longer published.', $tester->getDisplay());
	}//end testARefusalIsNamedAndTheRunExitsOne()
}//end class
