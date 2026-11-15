<?php

/**
 * Unit tests for the portaliq:contributions command.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contributing-apps-census/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\Contributions;
use OCA\Portaliq\Service\ContributionCensus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The census as lines and as JSON.
 *
 * @covers \OCA\Portaliq\Command\Contributions
 *
 * @spec openspec/changes/contributing-apps-census/specs/portal-contribution-contract/spec.md#requirement-an-administrator-can-see-which-apps-contribute-to-the-portal-req-cac-001
 */
class ContributionsCommandTest extends TestCase {

	/**
	 * A census with a contributing, a missing, a broken, an endpoint-only and an audience-less app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows(): array {
		return [
			[
				'app'       => 'dossiq',
				'status'    => ContributionCensus::STATUS_PROVIDER,
				'pattern'   => '',
				'error'     => '',
				'audiences' => [
					['audience' => 'client', 'collections' => 2, 'pages' => 1, 'actions' => 3],
					['audience' => 'business', 'collections' => 0, 'pages' => 0, 'actions' => 1],
				],
			],
			['app' => 'keepiq', 'status' => ContributionCensus::STATUS_NONE, 'pattern' => '', 'error' => '', 'audiences' => []],
			['app' => 'broken', 'status' => ContributionCensus::STATUS_ERROR, 'pattern' => '', 'error' => 'RuntimeException', 'audiences' => []],
			[
				'app'       => 'opencatalogi',
				'status'    => ContributionCensus::STATUS_ENDPOINTS,
				'pattern'   => 'public search and publication endpoints',
				'error'     => '',
				'audiences' => [],
			],
			['app' => 'silent', 'status' => ContributionCensus::STATUS_PROVIDER, 'pattern' => '', 'error' => '', 'audiences' => []],
		];
	}//end rows()

	/**
	 * The command over a census answering the fixed rows.
	 *
	 * @return Contributions The command.
	 */
	private function command(): Contributions {
		$census = $this->createMock(originalClassName: ContributionCensus::class);
		$census->expects($this->once())->method('rows')->willReturn(self::rows());

		return new Contributions(census: $census);
	}//end command()

	/**
	 * Every app gets one line saying what it contributes.
	 *
	 * @return void
	 */
	public function testEveryAppIsListedOnALine(): void {
		$output = new BufferedOutput();

		$this->assertSame(expected: 0, actual: $this->command()->run(new ArrayInput(parameters: []), $output));
		$this->assertSame(
			expected: implode(
				separator: PHP_EOL,
				array: [
					'dossiq: provider for client (2 collections, 1 pages, 3 actions); business (0 collections, 0 pages, 1 actions)',
					'keepiq: contributes nothing',
					'broken: provider failed: RuntimeException',
					'opencatalogi: integrates through its public search and publication endpoints, not a provider',
					'silent: provider serves no audience',
				]
			).PHP_EOL,
			actual: $output->fetch()
		);
	}//end testEveryAppIsListedOnALine()

	/**
	 * With --output=json the same rows are printed as JSON.
	 *
	 * @return void
	 */
	public function testJsonPrintsTheSameRows(): void {
		$output = new BufferedOutput();

		$this->assertSame(expected: 0, actual: $this->command()->run(new ArrayInput(parameters: ['--output' => 'JSON']), $output));
		$this->assertSame(expected: self::rows(), actual: json_decode(json: $output->fetch(), associative: true));
	}//end testJsonPrintsTheSameRows()

	/**
	 * An unknown format is refused before the census is read.
	 *
	 * @return void
	 */
	public function testAnUnknownFormatIsRefused(): void {
		$census = $this->createMock(originalClassName: ContributionCensus::class);
		$census->expects($this->never())->method('rows');
		$output = new BufferedOutput();

		$this->assertSame(expected: 1, actual: (new Contributions(census: $census))->run(new ArrayInput(parameters: ['--output' => 'xml']), $output));
		$this->assertStringContainsString(needle: 'Unknown output format "xml"', haystack: $output->fetch());
	}//end testAnUnknownFormatIsRefused()

	/**
	 * The command is registered under its documented name.
	 *
	 * @return void
	 */
	public function testTheCommandIsNamedAndRegistered(): void {
		$command = new Contributions(census: $this->createMock(originalClassName: ContributionCensus::class));

		$this->assertSame(expected: 'portaliq:contributions', actual: $command->getName());
		$this->assertStringContainsString(
			needle: '<command>OCA\\Portaliq\\Command\\Contributions</command>',
			haystack: (string) file_get_contents(filename: dirname(__DIR__, 3).'/appinfo/info.xml')
		);
	}//end testTheCommandIsNamedAndRegistered()
}//end class
