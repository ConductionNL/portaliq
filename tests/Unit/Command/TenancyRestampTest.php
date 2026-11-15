<?php

/**
 * `occ portaliq:tenancy:restamp` is a dry run unless `--apply` is given, and
 * prints the counts per organisation and the rows it cannot resolve.
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
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\TenancyRestamp;
use OCA\Portaliq\Service\Tenancy\PortalOrganisationRestamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Portaliq\Command\TenancyRestamp
 */
class TenancyRestampTest extends TestCase {

	/**
	 * Which of report/apply ran.
	 *
	 * @var array<int, string>
	 */
	private array $ran = [];

	/**
	 * Without apply it only reports.
	 *
	 * @return void
	 */
	public function testWithoutApplyItOnlyReports(): void {
		$tester = $this->tester(failed: 0);

		$this->assertSame(expected: 0, actual: $tester->execute([]));
		$this->assertSame(expected: ['report'], actual: $this->ran);
		$this->assertStringContainsString(needle: 'Dry run: nothing was written.', haystack: $tester->getDisplay());
		$this->assertStringContainsString(needle: 'gemeente-a, uuid-a: 1, 2, 0', haystack: $tester->getDisplay());
		$this->assertStringContainsString(needle: 'Organisation, uuid: already, to restamp, failed', haystack: $tester->getDisplay());
	}//end testWithoutApplyItOnlyReports()

	/**
	 * With apply it restamps.
	 *
	 * @return void
	 */
	public function testWithApplyItRestamps(): void {
		$tester = $this->tester(failed: 0);

		$this->assertSame(expected: 0, actual: $tester->execute(['--apply' => true]));
		$this->assertSame(expected: ['apply'], actual: $this->ran);
		$this->assertStringContainsString(needle: 'Restamp: rows were written.', haystack: $tester->getDisplay());
		$this->assertStringContainsString(needle: 'gemeente-a, uuid-a: 1, 3, 0', haystack: $tester->getDisplay());
	}//end testWithApplyItRestamps()

	/**
	 * The unresolved rows and unreadable schemas are printed.
	 *
	 * @return void
	 */
	public function testTheUnresolvedRowsAndUnreadableSchemasArePrinted(): void {
		$tester = $this->tester(failed: 0);

		$tester->execute([]);

		$this->assertStringContainsString(needle: 'Rows whose organisation does not resolve, left as they are: 1', haystack: $tester->getDisplay());
		$this->assertStringContainsString(needle: 'portalAccount acc-4: onbekend', haystack: $tester->getDisplay());
		$this->assertStringContainsString(needle: 'Schema portalPoll was not read completely: no such schema', haystack: $tester->getDisplay());
		$this->assertStringContainsString(
			needle: 'Rows stored in another organisation than they name, left for a person: 1',
			haystack: $tester->getDisplay()
		);
		$this->assertStringContainsString(needle: 'portalAccount acc-5: names gemeente-a, stored in uuid-b', haystack: $tester->getDisplay());
	}//end testTheUnresolvedRowsAndUnreadableSchemasArePrinted()

	/**
	 * Afailed write exits one.
	 *
	 * @return void
	 */
	public function testAFailedWriteExitsOne(): void {
		$this->assertSame(expected: 1, actual: $this->tester(failed: 1)->execute(['--apply' => true]));
	}//end testAFailedWriteExitsOne()

	/**
	 * Nothing that resolves is said.
	 *
	 * @return void
	 */
	public function testNothingThatResolvesIsSaid(): void {
		$restamp = $this->createMock(originalClassName: PortalOrganisationRestamp::class);
		$restamp->method('report')->willReturn(['apply' => false, 'organisations' => [], 'unresolved' => [], 'elsewhere' => [], 'unreadable' => []]);
		$tester = new CommandTester(new TenancyRestamp(restamp: $restamp));

		$this->assertSame(expected: 0, actual: $tester->execute([]));
		$this->assertStringContainsString(needle: '(no rows with an organisation that resolves)', haystack: $tester->getDisplay());
	}//end testNothingThatResolvesIsSaid()

	/**
	 * Without open register it exits one.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItExitsOne(): void {
		$restamp = $this->createMock(originalClassName: PortalOrganisationRestamp::class);
		$restamp->method('report')->willThrowException(new RuntimeException('OpenRegister is not available, so nothing was read or written.'));
		$tester = new CommandTester(new TenancyRestamp(restamp: $restamp));

		$this->assertSame(expected: 1, actual: $tester->execute([]));
		$this->assertStringContainsString(needle: 'OpenRegister is not available', haystack: $tester->getDisplay());
	}//end testWithoutOpenRegisterItExitsOne()

	/**
	 * The command over a restamp that answers fixed reports.
	 *
	 * @param int $failed Failed writes in the apply report.
	 *
	 * @return CommandTester
	 */
	private function tester(int $failed): CommandTester {
		$report = static fn (bool $apply): array => [
			'apply'         => $apply,
			'organisations' => [
				'gemeente-a' => ['uuid' => 'uuid-a', 'already' => 1, 'toRestamp' => 2, 'restamped' => 3, 'failed' => ((int)$apply * $failed)],
			],
			'unresolved'    => [['schema' => 'portalAccount', 'uuid' => 'acc-4', 'organisation' => 'onbekend']],
			'elsewhere'     => [['schema' => 'portalAccount', 'uuid' => 'acc-5', 'organisation' => 'gemeente-a', 'stored' => 'uuid-b']],
			'unreadable'    => ['portalPoll' => 'no such schema'],
		];

		$restamp = $this->createMock(originalClassName: PortalOrganisationRestamp::class);
		$restamp->method('report')->willReturnCallback(
			function () use ($report): array {
				$this->ran[] = 'report';
				return $report(false);
			}
		);
		$restamp->method('apply')->willReturnCallback(
			function () use ($report): array {
				$this->ran[] = 'apply';
				return $report(true);
			}
		);

		return new CommandTester(new TenancyRestamp(restamp: $restamp));
	}//end tester()
}//end class
