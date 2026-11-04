<?php

/**
 * A portal read for a subject runs acting for the portal's organisation in
 * OpenRegister, including the account claim read it needs; an anonymous read
 * runs as before, and a slug that names no organisation reads nothing.
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

use OCA\Portaliq\Service\PortalFieldProjector;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Tests\Doubles\ActingObjectService;
use OCA\Portaliq\Tests\Doubles\TenantHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Portaliq\Service\PortalObjectReader
 * @uses   \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 * @uses   \OCA\Portaliq\Service\Tenancy\OrganisationScopedObjectService
 * @uses   \OCA\Portaliq\Service\Tenancy\ActsForTheOrganisation
 * @uses   \OCA\Portaliq\Service\Tenancy\ClaimScopeCheck
 * @uses   \OCA\Portaliq\Service\Tenancy\SchemaTenancy
 * @uses   \OCA\Portaliq\Service\PortalFieldProjector
 * @uses   \OCA\Portaliq\Service\PortalFilteredRows
 * @uses   \OCA\Portaliq\Event\PortalClaimScopeEvent
 */
class PortalReadsActForTheOrganisationTest extends TestCase {
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
		$this->objectService->rows = [
			'portalMessage' => [
				['id' => 'm-1', 'subjectRef' => 's1', 'organisation' => 'gemeente-a', 'title' => 'Mine'],
				['id' => 'm-2', 'subjectRef' => 's1', 'organisation' => 'onbekend', 'title' => 'Unknown portal'],
			],
			'portalAccount' => [
				[
					'id' => 'acc-1',
					'subjectRef' => 's1',
					'audience' => 'resident',
					'organisation' => 'gemeente-a',
					'claims' => ['learniq' => ['guardianRef' => 'g-1']],
				],
			],
			'guardianNote' => [
				['id' => 'n-1', 'guardianRef' => 'g-1', 'organisation' => 'gemeente-a'],
			],
		];
		$this->objectService->found = ['m-1' => $this->objectService->rows['portalMessage'][0]];
	}//end setUp()

	/**
	 * Acollection is read acting for the portal organisation.
	 *
	 * @return void
	 */
	public function testACollectionIsReadActingForThePortalOrganisation(): void {
		$rows = $this->reader()->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a');

		$this->assertSame(expected: ['m-1'], actual: array_column($rows, 'id'));
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'findAll'));
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'setSchema'));
	}//end testACollectionIsReadActingForThePortalOrganisation()

	/**
	 * The claim read and the collection read both act for the organisation.
	 *
	 * @return void
	 */
	public function testTheClaimReadAndTheCollectionReadBothActForTheOrganisation(): void {
		$rows = $this->reader()->readCollection(
			register: 'learniq',
			schema: 'guardianNote',
			scopeField: 'guardianRef',
			subjectRef: 's1',
			organisation: 'gemeente-a',
			scopeClaim: 'learniq.guardianRef',
			audience: 'resident'
		);

		$this->assertSame(expected: ['n-1'], actual: array_column($rows, 'id'));
		$this->assertSame(expected: ['uuid-a', 'uuid-a'], actual: $this->objectService->actedFor(method: 'findAll'));
	}//end testTheClaimReadAndTheCollectionReadBothActForTheOrganisation()

	/**
	 * Ascoped page is read acting for the portal organisation.
	 *
	 * @return void
	 */
	public function testAScopedPageIsReadActingForThePortalOrganisation(): void {
		$page = $this->reader()->readScopedPage('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', [], 10, 0);

		$this->assertSame(expected: ['m-1'], actual: array_column($page['rows'], 'id'));
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'findAll'));
	}//end testAScopedPageIsReadActingForThePortalOrganisation()

	/**
	 * One row is read acting for the portal organisation.
	 *
	 * @return void
	 */
	public function testOneRowIsReadActingForThePortalOrganisation(): void {
		$row = $this->reader()->readObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'm-1', 'gemeente-a');

		$this->assertSame(expected: 'm-1', actual: $row['id']);
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'find'));
	}//end testOneRowIsReadActingForThePortalOrganisation()

	/**
	 * The write scope claim is resolved acting for the organisation.
	 *
	 * @return void
	 */
	public function testTheWriteScopeClaimIsResolvedActingForTheOrganisation(): void {
		$value = $this->reader()->resolveScopeValue(
			scopeClaim: 'learniq.guardianRef',
			contributingApp: 'learniq',
			subject: ['subjectRef' => 's1', 'audience' => 'resident', 'organisation' => 'gemeente-a']
		);

		$this->assertSame(expected: 'g-1', actual: $value);
		$this->assertSame(expected: ['uuid-a'], actual: $this->objectService->actedFor(method: 'findAll'));
	}//end testTheWriteScopeClaimIsResolvedActingForTheOrganisation()

	/**
	 * An anonymous collection is read as before.
	 *
	 * @return void
	 */
	public function testAnAnonymousCollectionIsReadAsBefore(): void {
		$this->reader()->readPublicCollection('portaliq', 'portalMessage', 'gemeente-a');

		$this->assertSame(expected: [null], actual: $this->objectService->actedFor(method: 'findAll'));
	}//end testAnAnonymousCollectionIsReadAsBefore()

	/**
	 * Aread without an organisation runs as before.
	 *
	 * @return void
	 */
	public function testAReadWithoutAnOrganisationRunsAsBefore(): void {
		$this->reader()->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1');

		$this->assertSame(expected: [null], actual: $this->objectService->actedFor(method: 'findAll'));
	}//end testAReadWithoutAnOrganisationRunsAsBefore()

	/**
	 * Aslug that names no organisation reads nothing.
	 *
	 * @return void
	 */
	public function testASlugThatNamesNoOrganisationReadsNothing(): void {
		$reader = $this->reader();

		$this->assertSame(expected: [], actual: $reader->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend'));
		$this->assertNull(actual: $reader->readScopedPage('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', [], 10, 0));
		$this->assertNull(actual: $reader->readObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'm-1', 'onbekend'));
		$this->assertSame(expected: [], actual: $this->objectService->calls);
	}//end testASlugThatNamesNoOrganisationReadsNothing()

	/**
	 * The reader over the acting object service.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(): PortalObjectReader {
		return new PortalObjectReader(
			container: $this->tenantContainer(objectService: $this->objectService),
			logger: new NullLogger(),
			projector: new PortalFieldProjector(logger: new NullLogger())
		);
	}//end reader()
}//end class
