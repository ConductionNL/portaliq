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

	public function testWithoutApplyItOnlyReports(): void {
		$tester = $this->tester(failed: 0);

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(['report'], $this->ran);
		$this->assertStringContainsString('Dry run: nothing was written.', $tester->getDisplay());
		$this->assertStringContainsString('gemeente-a, uuid-a: 1, 2, 0', $tester->getDisplay());
		$this->assertStringContainsString('Organisation, uuid: already, to restamp, failed', $tester->getDisplay());
	}//end testWithoutApplyItOnlyReports()

	public function testWithApplyItRestamps(): void {
		$tester = $this->tester(failed: 0);

		$this->assertSame(0, $tester->execute(['--apply' => true]));
		$this->assertSame(['apply'], $this->ran);
		$this->assertStringContainsString('Restamp: rows were written.', $tester->getDisplay());
		$this->assertStringContainsString('gemeente-a, uuid-a: 1, 3, 0', $tester->getDisplay());
	}//end testWithApplyItRestamps()

	public function testTheUnresolvedRowsAndUnreadableSchemasArePrinted(): void {
		$tester = $this->tester(failed: 0);

		$tester->execute([]);

		$this->assertStringContainsString('Rows whose organisation does not resolve, left as they are: 1', $tester->getDisplay());
		$this->assertStringContainsString('portalAccount acc-4: onbekend', $tester->getDisplay());
		$this->assertStringContainsString('Schema portalPoll was not read completely: no such schema', $tester->getDisplay());
		$this->assertStringContainsString('Rows stored in another organisation than they name, left for a person: 1', $tester->getDisplay());
		$this->assertStringContainsString('portalAccount acc-5: names gemeente-a, stored in uuid-b', $tester->getDisplay());
	}//end testTheUnresolvedRowsAndUnreadableSchemasArePrinted()

	public function testAFailedWriteExitsOne(): void {
		$this->assertSame(1, $this->tester(failed: 1)->execute(['--apply' => true]));
	}//end testAFailedWriteExitsOne()

	public function testNothingThatResolvesIsSaid(): void {
		$restamp = $this->createMock(PortalOrganisationRestamp::class);
		$restamp->method('report')->willReturn(['apply' => false, 'organisations' => [], 'unresolved' => [], 'elsewhere' => [], 'unreadable' => []]);
		$tester = new CommandTester(new TenancyRestamp(restamp: $restamp));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('(no rows with an organisation that resolves)', $tester->getDisplay());
	}//end testNothingThatResolvesIsSaid()

	public function testWithoutOpenRegisterItExitsOne(): void {
		$restamp = $this->createMock(PortalOrganisationRestamp::class);
		$restamp->method('report')->willThrowException(new RuntimeException('OpenRegister is not available, so nothing was read or written.'));
		$tester = new CommandTester(new TenancyRestamp(restamp: $restamp));

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('OpenRegister is not available', $tester->getDisplay());
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
				'gemeente-a' => ['uuid' => 'uuid-a', 'already' => 1, 'toRestamp' => 2, 'restamped' => 3, 'failed' => ($apply === true ? $failed : 0)],
			],
			'unresolved'    => [['schema' => 'portalAccount', 'uuid' => 'acc-4', 'organisation' => 'onbekend']],
			'elsewhere'     => [['schema' => 'portalAccount', 'uuid' => 'acc-5', 'organisation' => 'gemeente-a', 'stored' => 'uuid-b']],
			'unreadable'    => ['portalPoll' => 'no such schema'],
		];

		$restamp = $this->createMock(PortalOrganisationRestamp::class);
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
