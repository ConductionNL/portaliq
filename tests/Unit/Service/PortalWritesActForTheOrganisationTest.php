<?php

/**
 * A portal write for a subject runs acting for the portal's organisation in
 * OpenRegister, the ownership re-read included; an anonymous submission runs
 * as before, and a slug that names no organisation writes nothing.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Tests\Doubles\ActingObjectService;
use OCA\Portaliq\Tests\Doubles\TenantHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Portaliq\Service\PortalObjectWriter
 * @uses   \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 * @uses   \OCA\Portaliq\Service\Tenancy\OrganisationScopedObjectService
 * @uses   \OCA\Portaliq\Service\Tenancy\ActsForTheOrganisation
 */
class PortalWritesActForTheOrganisationTest extends TestCase {
	use TenantHarness;

	private ActingObjectService $objectService;

	protected function setUp(): void {
		$this->objectService = new ActingObjectService();
		$this->objectService->found = [
			'row-1' => ['id' => 'row-1', 'subjectRef' => 's1', 'organisation' => 'gemeente-a', 'status' => 'open'],
		];
	}//end setUp()

	public function testACreateIsWrittenActingForThePortalOrganisation(): void {
		$saved = $this->writer()->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', ['title' => 'X']);

		$this->assertSame('gemeente-a', $saved['organisation']);
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'saveObject'));
	}//end testACreateIsWrittenActingForThePortalOrganisation()

	public function testAnUpdateReadsAndWritesActingForThePortalOrganisation(): void {
		$saved = $this->writer()->updateObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', 'row-1', ['status' => 'read']);

		$this->assertSame('read', $saved['status']);
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'find'));
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'saveObject'));
	}//end testAnUpdateReadsAndWritesActingForThePortalOrganisation()

	public function testADeleteReadsAndDeletesActingForThePortalOrganisation(): void {
		$this->assertTrue($this->writer()->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', 'row-1'));

		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'find'));
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'deleteObject'));
	}//end testADeleteReadsAndDeletesActingForThePortalOrganisation()

	public function testAnAnonymousSubmissionIsWrittenAsBefore(): void {
		$this->writer()->createAnonymousObject('portaliq', 'landingPageSubmission', ['name' => 'Anna']);

		$this->assertSame([null], $this->objectService->actedFor(method: 'saveObject'));
	}//end testAnAnonymousSubmissionIsWrittenAsBefore()

	public function testAWriteWithoutAnOrganisationRunsAsBefore(): void {
		$this->writer()->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', '', ['title' => 'X']);

		$this->assertSame([null], $this->objectService->actedFor(method: 'saveObject'));
	}//end testAWriteWithoutAnOrganisationRunsAsBefore()

	public function testASlugThatNamesNoOrganisationWritesNothing(): void {
		$writer = $this->writer();

		$this->assertNull($writer->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', ['title' => 'X']));
		$this->assertNull($writer->updateObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', 'row-1', ['status' => 'read']));
		$this->assertFalse($writer->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', 'row-1'));
		$this->assertSame([], $this->objectService->calls);
	}//end testASlugThatNamesNoOrganisationWritesNothing()

	/**
	 * The writer over the acting object service.
	 *
	 * @return PortalObjectWriter
	 */
	private function writer(): PortalObjectWriter {
		return new PortalObjectWriter($this->tenantContainer(objectService: $this->objectService), new NullLogger());
	}//end writer()
}//end class
