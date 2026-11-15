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

	/**
	 * Object service.
	 *
	 * @var ActingObjectService
	 */
	private ActingObjectService $objectService;

	/**
	 * Builds the fixture for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = new ActingObjectService();
		$this->objectService->found = [
			'row-1' => ['id' => 'row-1', 'subjectRef' => 's1', 'organisation' => 'gemeente-a', 'status' => 'open'],
		];
	}//end setUp()

	/**
	 * Acreate is written acting for the portal organisation.
	 *
	 * @return void
	 */
	public function testACreateIsWrittenActingForThePortalOrganisation(): void {
		$saved = $this->writer()->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', ['title' => 'X']);

		$this->assertSame(expected: 'gemeente-a', actual: $saved['organisation']);
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'saveObject'));
	}//end testACreateIsWrittenActingForThePortalOrganisation()

	/**
	 * An update reads and writes acting for the portal organisation.
	 *
	 * @return void
	 */
	public function testAnUpdateReadsAndWritesActingForThePortalOrganisation(): void {
		$saved = $this->writer()->updateObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', 'row-1', ['status' => 'read']);

		$this->assertSame(expected: 'read', actual: $saved['status']);
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'find'));
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'saveObject'));
	}//end testAnUpdateReadsAndWritesActingForThePortalOrganisation()

	/**
	 * Adelete reads and deletes acting for the portal organisation.
	 *
	 * @return void
	 */
	public function testADeleteReadsAndDeletesActingForThePortalOrganisation(): void {
		$this->assertTrue(condition: $this->writer()->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', 'row-1'));

		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'find'));
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'deleteObject'));
	}//end testADeleteReadsAndDeletesActingForThePortalOrganisation()

	/**
	 * An anonymous submission is written as before.
	 *
	 * @return void
	 */
	public function testAnAnonymousSubmissionIsWrittenAsBefore(): void {
		$this->writer()->createAnonymousObject('portaliq', 'landingPageSubmission', ['name' => 'Anna']);

		$this->assertSame(expected: [null], actual: $this->objectService->actedFor(method: 'saveObject'));
	}//end testAnAnonymousSubmissionIsWrittenAsBefore()

	/**
	 * Awrite without an organisation runs as before.
	 *
	 * @return void
	 */
	public function testAWriteWithoutAnOrganisationRunsAsBefore(): void {
		$this->writer()->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', '', ['title' => 'X']);

		$this->assertSame(expected: [null], actual: $this->objectService->actedFor(method: 'saveObject'));
	}//end testAWriteWithoutAnOrganisationRunsAsBefore()

	/**
	 * Aslug that names no organisation writes nothing.
	 *
	 * @return void
	 */
	public function testASlugThatNamesNoOrganisationWritesNothing(): void {
		$writer = $this->writer();

		$this->assertNull(actual: $writer->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', ['title' => 'X']));
		$this->assertNull(actual: $writer->updateObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', 'row-1', ['status' => 'read']));
		$this->assertFalse(condition: $writer->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', 'row-1'));
		$this->assertSame(expected: [], actual: $this->objectService->calls);
	}//end testASlugThatNamesNoOrganisationWritesNothing()

	/**
	 * The writer over the acting object service.
	 *
	 * @return PortalObjectWriter
	 */
	private function writer(): PortalObjectWriter {
		return new PortalObjectWriter(container: $this->tenantContainer(objectService: $this->objectService), logger: new NullLogger());
	}//end writer()
}//end class
