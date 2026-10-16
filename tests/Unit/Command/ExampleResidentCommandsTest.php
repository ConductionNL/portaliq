<?php

/**
 * Unit tests for the example resident remove command, which stays one
 * release after the install command retired (resident-on-example-set).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\ExampleResidentRemove;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The remove command turns the remover's report into lines and an exit code,
 * and points at OpenRegister's example set for the next install.
 */
class ExampleResidentCommandsTest extends TestCase {
	/**
	 * The remove command reports per part, names what stayed with the reason,
	 * and says where the resident comes from now.
	 *
	 * @return void
	 */
	public function testRemoveReportsWhatWasDeleted(): void {
		$remover = $this->createMock(ExampleResidentRemover::class);
		$remover->expects($this->once())->method('remove')->with('zuiddrecht', 'zuiddrecht')->willReturn(
			[
				'id'        => 'zuiddrecht',
				'recorded'  => true,
				'available' => true,
				'userId'    => 'sanne.devries',
				'types'     => ['dossiq portaalBericht' => ['deleted' => 4, 'gone' => 0], 'dossiq case' => ['deleted' => 0, 'gone' => 1]],
				'failed'    => ['dossiq case woo-fietspad'],
				'account'   => 'deleted',
				'signIn'    => 'kept-not-ours',
				'user'      => 'deleted',
			]
		);
		$output = new BufferedOutput();
		$code   = (new ExampleResidentRemove($remover))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text   = $output->fetch();

		$this->assertSame(2, $code);
		$this->assertStringContainsString('dossiq portaalBericht: 4 deleted, 0 already gone', $text);
		$this->assertStringContainsString('Portal account: deleted', $text);
		$this->assertStringContainsString('offered it before the install', $text);
		$this->assertStringContainsString('Could not be deleted: dossiq case woo-fietspad', $text);
		$this->assertStringContainsString('archive record', $text);
		$this->assertStringContainsString('occ openregister:example-set:install zuiddrecht', $text);
	}//end testRemoveReportsWhatWasDeleted()

	/**
	 * Without a record the command exits 1 and points at the example set.
	 *
	 * @return void
	 */
	public function testNothingRecordedExitsOne(): void {
		$remover = $this->createMock(ExampleResidentRemover::class);
		$remover->method('remove')->willReturn(['recorded' => false, 'available' => true]);
		$output = new BufferedOutput();
		$code   = (new ExampleResidentRemove($remover))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text   = $output->fetch();

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Nothing is recorded', $text);
		$this->assertStringContainsString('occ openregister:example-set:install zuiddrecht', $text);
	}//end testNothingRecordedExitsOne()

	/**
	 * `--portal` names the portal when it is not named like the site.
	 *
	 * @return void
	 */
	public function testThePortalOptionReachesTheRemover(): void {
		$remover = $this->createMock(ExampleResidentRemover::class);
		$remover->expects($this->once())->method('remove')->with('zuiddrecht', 'elders')->willReturn(['recorded' => false, 'available' => true]);

		(new ExampleResidentRemove($remover))->run(new ArrayInput(['site' => 'zuiddrecht', '--portal' => 'elders']), new BufferedOutput());
	}//end testThePortalOptionReachesTheRemover()
}//end class
