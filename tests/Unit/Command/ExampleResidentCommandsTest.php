<?php

/**
 * Unit tests for the example resident commands.
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

use OCA\Portaliq\Command\ExampleResidentInstall;
use OCA\Portaliq\Command\ExampleResidentRemove;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentCatalogue;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentInstaller;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The commands turn the installer's and remover's reports into lines and an
 * exit code. The catalogue is the real one, so the shipped resident is used.
 */
class ExampleResidentCommandsTest extends TestCase {
	/**
	 * A complete install exits 0, shows the password once and says where to sign in.
	 *
	 * @return void
	 */
	public function testInstallReportsWhatArrived(): void {
		$installer = $this->createMock(ExampleResidentInstaller::class);
		$installer->expects($this->once())->method('install')
			->with(
				$this->callback(static fn (array $resident): bool => $resident['id'] === 'zuiddrecht' && count($resident['objects']) === 10),
				'',
				''
			)
			->willReturn($this->report());

		$output = new BufferedOutput();
		$code = (new ExampleResidentInstall(new ExampleResidentCatalogue(), $installer))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text = $output->fetch();

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Nextcloud account sanne.devries: created', $text);
		$this->assertStringContainsString('Sign-in mode nextcloud on portal zuiddrecht: added', $text);
		$this->assertStringContainsString('dossiq case: 5 declared, 5 created, 0 already there, 5 found afterwards', $text);
		$this->assertStringContainsString('shown once, here: geheim', $text);
		$this->assertStringContainsString('route=/mijn with the card "Voorbeeldinwoner"', $text);
	}//end testInstallReportsWhatArrived()

	/**
	 * The password from the environment is passed on, and the option refuses an empty one.
	 *
	 * @return void
	 */
	public function testInstallTakesThePasswordFromTheEnvironment(): void {
		$installer = $this->createMock(ExampleResidentInstaller::class);
		$installer->expects($this->once())->method('install')
			->with($this->anything(), 'demo-inwoner', 'uit-de-omgeving')
			->willReturn($this->report(['userId' => 'demo-inwoner', 'password' => '']));

		putenv('OC_PASS=uit-de-omgeving');
		$output = new BufferedOutput();
		$code = (new ExampleResidentInstall(new ExampleResidentCatalogue(), $installer))->run(
			new ArrayInput(['site' => 'zuiddrecht', '--user' => 'demo-inwoner', '--password-from-env' => true]),
			$output
		);
		putenv('OC_PASS');

		$this->assertSame(0, $code);
		$this->assertStringNotContainsString('shown once', $output->fetch());

		$installer = $this->createMock(ExampleResidentInstaller::class);
		$installer->expects($this->never())->method('install');
		$output = new BufferedOutput();
		$code = (new ExampleResidentInstall(new ExampleResidentCatalogue(), $installer))->run(
			new ArrayInput(['site' => 'zuiddrecht', '--password-from-env' => true]),
			$output
		);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('OC_PASS', $output->fetch());
	}//end testInstallTakesThePasswordFromTheEnvironment()

	/**
	 * What was dropped, missing or lost exits 2 and is named; a stop exits 1.
	 *
	 * @return void
	 */
	public function testInstallExitsTwoOrOne(): void {
		$installer = $this->createMock(ExampleResidentInstaller::class);
		$installer->method('install')->willReturn(
			$this->report(
				[
					'dropped' => ['dossiq case woo-fietspad: no caseType with identifier "woo-verzoek" on this instance'],
					'lost'    => ['dossiq case vergunning-dakkapel: waitingOnApplicant'],
					'missing' => ['portal account sanne.devries'],
					'ok'      => false,
				]
			)
		);
		$output = new BufferedOutput();
		$code = (new ExampleResidentInstall(new ExampleResidentCatalogue(), $installer))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text = $output->fetch();

		$this->assertSame(2, $code);
		$this->assertStringContainsString('Not written: dossiq case woo-fietspad: no caseType', $text);
		$this->assertStringContainsString('Written but not kept: dossiq case vergunning-dakkapel: waitingOnApplicant', $text);
		$this->assertStringContainsString('Not on the instance: portal account sanne.devries', $text);

		$installer = $this->createMock(ExampleResidentInstaller::class);
		$installer->method('install')->willReturn($this->report(['stopped' => 'A Nextcloud account "sanne.devries" exists']));
		$output = new BufferedOutput();
		$code = (new ExampleResidentInstall(new ExampleResidentCatalogue(), $installer))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('"sanne.devries" exists', $output->fetch());

		$output = new BufferedOutput();
		$code = (new ExampleResidentInstall(new ExampleResidentCatalogue(), $installer))->run(new ArrayInput(['site' => 'nergens']), $output);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('zuiddrecht', $output->fetch());
	}//end testInstallExitsTwoOrOne()

	/**
	 * The remove command reports per part, and names what stayed with the reason.
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
		$code = (new ExampleResidentRemove(new ExampleResidentCatalogue(), $remover))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text = $output->fetch();

		$this->assertSame(2, $code);
		$this->assertStringContainsString('dossiq portaalBericht: 4 deleted, 0 already gone', $text);
		$this->assertStringContainsString('Portal account: deleted', $text);
		$this->assertStringContainsString('offered it before the install', $text);
		$this->assertStringContainsString('Could not be deleted: dossiq case woo-fietspad', $text);
		$this->assertStringContainsString('archive record', $text);

		$remover = $this->createMock(ExampleResidentRemover::class);
		$remover->method('remove')->willReturn(['recorded' => false, 'available' => true]);
		$output = new BufferedOutput();
		$code = (new ExampleResidentRemove(new ExampleResidentCatalogue(), $remover))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('Nothing is recorded', $output->fetch());
	}//end testRemoveReportsWhatWasDeleted()

	/**
	 * A complete report, with overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to change.
	 *
	 * @return array<string, mixed>
	 */
	private function report(array $overrides = []): array {
		return array_merge(
			[
				'id'       => 'zuiddrecht',
				'portal'   => 'zuiddrecht',
				'userId'   => 'sanne.devries',
				'stopped'  => '',
				'user'     => 'created',
				'password' => 'geheim',
				'account'  => 'created',
				'signIn'   => 'added',
				'types'    => [
					'dossiq case'               => ['declared' => 5, 'created' => 5, 'kept' => 0, 'arrived' => 5],
					'dossiq aanvullingsverzoek' => ['declared' => 1, 'created' => 1, 'kept' => 0, 'arrived' => 1],
					'dossiq portaalBericht'     => ['declared' => 4, 'created' => 4, 'kept' => 0, 'arrived' => 4],
				],
				'dropped'  => [],
				'missing'  => [],
				'lost'     => [],
				'ok'       => true,
			],
			$overrides
		);
	}//end report()
}//end class
