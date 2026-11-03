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

	private ActingObjectService $objectService;

	protected function setUp(): void {
		$this->objectService = new ActingObjectService();
		$this->objectService->rows = [
			'portalMessage' => [
				['id' => 'm-1', 'subjectRef' => 's1', 'organisation' => 'gemeente-a', 'title' => 'Mine'],
				['id' => 'm-2', 'subjectRef' => 's1', 'organisation' => 'onbekend', 'title' => 'Unknown portal'],
			],
			'portalAccount' => [
				['id' => 'acc-1', 'subjectRef' => 's1', 'audience' => 'resident', 'organisation' => 'gemeente-a', 'claims' => ['learniq' => ['guardianRef' => 'g-1']]],
			],
			'guardianNote' => [
				['id' => 'n-1', 'guardianRef' => 'g-1', 'organisation' => 'gemeente-a'],
			],
		];
		$this->objectService->found = ['m-1' => $this->objectService->rows['portalMessage'][0]];
	}//end setUp()

	public function testACollectionIsReadActingForThePortalOrganisation(): void {
		$rows = $this->reader()->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a');

		$this->assertSame(['m-1'], array_column($rows, 'id'));
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'findAll'));
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'setSchema'));
	}//end testACollectionIsReadActingForThePortalOrganisation()

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

		$this->assertSame(['n-1'], array_column($rows, 'id'));
		$this->assertSame(['uuid-a', 'uuid-a'], $this->objectService->actedFor(method: 'findAll'));
	}//end testTheClaimReadAndTheCollectionReadBothActForTheOrganisation()

	public function testAScopedPageIsReadActingForThePortalOrganisation(): void {
		$page = $this->reader()->readScopedPage('portaliq', 'portalMessage', 'subjectRef', 's1', 'gemeente-a', [], 10, 0);

		$this->assertSame(['m-1'], array_column($page['rows'], 'id'));
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'findAll'));
	}//end testAScopedPageIsReadActingForThePortalOrganisation()

	public function testOneRowIsReadActingForThePortalOrganisation(): void {
		$row = $this->reader()->readObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'm-1', 'gemeente-a');

		$this->assertSame('m-1', $row['id']);
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'find'));
	}//end testOneRowIsReadActingForThePortalOrganisation()

	public function testTheWriteScopeClaimIsResolvedActingForTheOrganisation(): void {
		$value = $this->reader()->resolveScopeValue(
			scopeClaim: 'learniq.guardianRef',
			contributingApp: 'learniq',
			subject: ['subjectRef' => 's1', 'audience' => 'resident', 'organisation' => 'gemeente-a']
		);

		$this->assertSame('g-1', $value);
		$this->assertSame(['uuid-a'], $this->objectService->actedFor(method: 'findAll'));
	}//end testTheWriteScopeClaimIsResolvedActingForTheOrganisation()

	public function testAnAnonymousCollectionIsReadAsBefore(): void {
		$this->reader()->readPublicCollection('portaliq', 'portalMessage', 'gemeente-a');

		$this->assertSame([null], $this->objectService->actedFor(method: 'findAll'));
	}//end testAnAnonymousCollectionIsReadAsBefore()

	public function testAReadWithoutAnOrganisationRunsAsBefore(): void {
		$this->reader()->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1');

		$this->assertSame([null], $this->objectService->actedFor(method: 'findAll'));
	}//end testAReadWithoutAnOrganisationRunsAsBefore()

	public function testASlugThatNamesNoOrganisationReadsNothing(): void {
		$reader = $this->reader();

		$this->assertSame([], $reader->readCollection('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend'));
		$this->assertNull($reader->readScopedPage('portaliq', 'portalMessage', 'subjectRef', 's1', 'onbekend', [], 10, 0));
		$this->assertNull($reader->readObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'm-1', 'onbekend'));
		$this->assertSame([], $this->objectService->calls);
	}//end testASlugThatNamesNoOrganisationReadsNothing()

	/**
	 * The reader over the acting object service.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(): PortalObjectReader {
		return new PortalObjectReader(
			$this->tenantContainer(objectService: $this->objectService),
			new NullLogger(),
			new PortalFieldProjector(new NullLogger())
		);
	}//end reader()
}//end class
