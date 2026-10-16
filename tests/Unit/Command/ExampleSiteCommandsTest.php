<?php

/**
 * Unit tests for the portaliq:example-site commands.
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

use OCA\Portaliq\Command\ExampleSiteInstall;
use OCA\Portaliq\Command\ExampleSiteRemove;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteCatalogue;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteInstaller;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteRemover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The commands turn the installer's report into lines and an exit code. The
 * catalogue is the real one, so the commands are tried with the shipped site.
 */
class ExampleSiteCommandsTest extends TestCase {
	/**
	 * A complete install exits 0 and says where the site is.
	 *
	 * @return void
	 */
	public function testInstallReportsWhatArrived(): void {
		$installer = $this->createMock(ExampleSiteInstaller::class);
		$installer->expects($this->once())->method('install')
			->with($this->callback(static fn (array $site): bool => $site['id'] === 'zuiddrecht' && count($site['pages']) === 36))
			->willReturn($this->report());

		$output = new BufferedOutput();
		$code = (new ExampleSiteInstall(new ExampleSiteCatalogue(), $installer))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text = $output->fetch();

		$this->assertSame(0, $code);
		$this->assertStringContainsString('Pages: 33 declared, 33 created, 0 already there, 33 found afterwards', $text);
		$this->assertStringContainsString('/index.php/apps/portaliq/site?portal=zuiddrecht', $text);
		$this->assertStringNotContainsString('house style', $text);
	}//end testInstallReportsWhatArrived()

	/**
	 * A lost key or a missing object exits 2 and is named; a theme the theme
	 * app does not offer is said, and is no failure.
	 *
	 * @return void
	 */
	public function testInstallExitsTwoWhenSomethingDidNotArrive(): void {
		$report = $this->report();
		$report['ok'] = false;
		$report['themeOffered'] = false;
		$report['missing'] = ['page /afval'];
		$report['lost'] = ['portal zuiddrecht: headerSearch.label'];
		$installer = $this->createMock(ExampleSiteInstaller::class);
		$installer->method('install')->willReturn($report);

		$output = new BufferedOutput();
		$code = (new ExampleSiteInstall(new ExampleSiteCatalogue(), $installer))->run(new ArrayInput(['site' => 'zuiddrecht']), $output);
		$text = $output->fetch();

		$this->assertSame(2, $code);
		$this->assertStringContainsString('Not on the instance: page /afval', $text);
		$this->assertStringContainsString('Written but not kept: portal zuiddrecht: headerSearch.label', $text);
		$this->assertStringContainsString('The theme app does not offer the set "zuiddrecht"', $text);
		$this->assertStringContainsString('occ maintenance:repair', $text);
	}//end testInstallExitsTwoWhenSomethingDidNotArrive()

	/**
	 * An unknown site and a missing OpenRegister both exit 1, and neither
	 * claims that something was installed.
	 *
	 * @return void
	 */
	public function testInstallExitsOneWhenNothingCouldBeDone(): void {
		$installer = $this->createMock(ExampleSiteInstaller::class);
		$installer->method('install')->willReturn(['available' => false] + $this->report());

		$output = new BufferedOutput();
		$command = new ExampleSiteInstall(new ExampleSiteCatalogue(), $installer);
		$this->assertSame(1, $command->run(new ArrayInput(['site' => 'nergens']), $output));
		$this->assertStringContainsString('Sites you can install: zuiddrecht', $output->fetch());

		$this->assertSame(1, $command->run(new ArrayInput(['site' => 'zuiddrecht']), $output));
		$text = $output->fetch();
		$this->assertStringContainsString('OpenRegister is not available', $text);
		$this->assertStringNotContainsString('The site is installed', $text);
	}//end testInstallExitsOneWhenNothingCouldBeDone()

	/**
	 * Remove reports the counts and what happened to the portal.
	 *
	 * @return void
	 */
	public function testRemoveReportsWhatWasDeleted(): void {
		$remover = $this->createMock(ExampleSiteRemover::class);
		$remover->method('remove')->with('zuiddrecht', 'zuiddrecht')->willReturnOnConsecutiveCalls(
			[
				'recorded' => true,
				'available' => true,
				'deleted' => ['newsItem' => 4, 'page' => 33, 'menu' => 3],
				'gone' => ['newsItem' => 0, 'page' => 0, 'menu' => 0],
				'failed' => [],
				'portal' => 'deleted',
			],
			[
				'recorded' => true,
				'available' => true,
				'deleted' => ['newsItem' => 4, 'page' => 32, 'menu' => 3],
				'gone' => ['newsItem' => 0, 'page' => 1, 'menu' => 0],
				'failed' => [],
				'portal' => 'kept-content',
			],
			['recorded' => false, 'available' => true, 'deleted' => [], 'gone' => [], 'failed' => [], 'portal' => 'kept-not-ours'],
			['recorded' => true, 'available' => false, 'deleted' => [], 'gone' => [], 'failed' => [], 'portal' => 'kept-not-ours'],
		);
		$command = new ExampleSiteRemove(new ExampleSiteCatalogue(), $remover);

		$output = new BufferedOutput();
		$this->assertSame(0, $command->run(new ArrayInput(['site' => 'zuiddrecht']), $output));
		$text = $output->fetch();
		$this->assertStringContainsString('Pages: 33 deleted, 0 already gone', $text);
		$this->assertStringContainsString('Portal: deleted', $text);

		$this->assertSame(2, $command->run(new ArrayInput(['site' => 'zuiddrecht']), $output));
		$text = $output->fetch();
		$this->assertStringContainsString('Pages: 32 deleted, 1 already gone', $text);
		$this->assertStringContainsString('It still holds menus or pages that are not from the example site', $text);

		$this->assertSame(1, $command->run(new ArrayInput(['site' => 'zuiddrecht']), $output));
		$this->assertStringContainsString('Nothing is recorded for "zuiddrecht"', $output->fetch());

		$this->assertSame(1, $command->run(new ArrayInput(['site' => 'zuiddrecht']), $output));
		$this->assertStringContainsString('OpenRegister is not available, so nothing was deleted', $output->fetch());

		$this->assertSame(1, $command->run(new ArrayInput(['site' => 'nergens']), $output));
		$this->assertStringContainsString('Sites this app ships: zuiddrecht', $output->fetch());
	}//end testRemoveReportsWhatWasDeleted()

	/**
	 * A complete install report for the shipped site.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return [
			'site' => 'zuiddrecht',
			'portal' => 'zuiddrecht',
			'available' => true,
			'themeOffered' => true,
			'types' => [
				'portal' => ['declared' => 1, 'created' => 1, 'kept' => 0, 'arrived' => 1],
				'menu' => ['declared' => 3, 'created' => 3, 'kept' => 0, 'arrived' => 3],
				'page' => ['declared' => 33, 'created' => 33, 'kept' => 0, 'arrived' => 33],
				'newsItem' => ['declared' => 4, 'created' => 4, 'kept' => 0, 'arrived' => 4],
			],
			'missing' => [],
			'lost' => [],
			'ok' => true,
		];
	}//end report()
}//end class
