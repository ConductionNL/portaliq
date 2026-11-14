<?php

/**
 * Unit tests for the EraseReportContactJob.
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
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\EraseReportContactJob;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Tests\Unit\Service\Publication\PublicationReportFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A closed report's contact address goes 30 days after the close; an open
 * report and a recent close keep theirs.
 *
 * @covers \OCA\Portaliq\BackgroundJob\EraseReportContactJob
 * @uses   \OCA\Portaliq\Service\Publication\PublicationReportService
 * @uses   \OCA\Portaliq\Service\Publication\PublicPublicationRead
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
class EraseReportContactJobTest extends TestCase {
	/**
	 * The recorded boundaries.
	 *
	 * @var PublicationReportFixture
	 */
	private PublicationReportFixture $fixture;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new PublicationReportFixture(test: $this);
	}//end setUp()

	/**
	 * Run the job once over one portal.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$portals = $this->createMock(PortalResolverInterface::class);
		$portals->method('allPublishedPortals')->willReturn([PublicationReportFixture::portal()]);

		$job = new EraseReportContactJob($this->createMock(ITimeFactory::class), $portals, $this->fixture->service());
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * A report closed 31 days ago loses its address; one closed 29 days ago
	 * keeps it.
	 *
	 * @return void
	 */
	public function testTheAddressIsErasedAfter30Days(): void {
		$this->fixture->rows = [
			['id' => 'old', 'portal' => 'zuiddrecht', 'status' => 'handled', 'closedAt' => '2026-09-01T09:00:00+00:00', 'contactEmail' => 'a@example.nl'],
			['id' => 'recent', 'portal' => 'zuiddrecht', 'status' => 'not-applicable', 'closedAt' => '2026-09-03T09:00:00+00:00', 'contactEmail' => 'b@example.nl'],
		];

		$this->runJob();

		$this->assertSame(expected: [['portal', 'zuiddrecht', 'old', ['contactEmail' => '']]], actual: $this->fixture->updated);
	}//end testTheAddressIsErasedAfter30Days()

	/**
	 * An open report keeps its address however old it is, and a report
	 * without an address or a close date is left alone.
	 *
	 * @return void
	 */
	public function testAnOpenReportKeepsItsAddress(): void {
		$this->fixture->rows = [
			['id' => 'open', 'portal' => 'zuiddrecht', 'status' => 'new', 'receivedAt' => '2025-01-01T09:00:00+00:00', 'contactEmail' => 'a@example.nl'],
			['id' => 'no-address', 'portal' => 'zuiddrecht', 'status' => 'handled', 'closedAt' => '2025-01-01T09:00:00+00:00', 'contactEmail' => ''],
			['id' => 'no-date', 'portal' => 'zuiddrecht', 'status' => 'handled', 'contactEmail' => 'c@example.nl'],
		];

		$this->runJob();

		$this->assertSame(expected: [], actual: $this->fixture->updated);
	}//end testAnOpenReportKeepsItsAddress()

	/**
	 * The job is registered with the scheduler in info.xml.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');

		$this->assertStringContainsString(needle: '<job>OCA\Portaliq\BackgroundJob\EraseReportContactJob</job>', haystack: $info);
	}//end testTheJobIsRegistered()
}//end class
