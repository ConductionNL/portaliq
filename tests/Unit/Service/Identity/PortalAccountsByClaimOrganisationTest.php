<?php

/**
 * The accounts a claim-addressed change notice reaches are listed acting for
 * the changed record's organisation.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
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

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalAccountsByClaim;
use OCA\Portaliq\Tests\Doubles\ActingObjectService;
use OCA\Portaliq\Tests\Doubles\TenantHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Portaliq\Service\Identity\PortalAccountsByClaim
 * @uses   \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 * @uses   \OCA\Portaliq\Service\Tenancy\OrganisationScopedObjectService
 * @uses   \OCA\Portaliq\Service\Tenancy\ActsForTheOrganisation
 * @uses   \OCA\Portaliq\Service\Tenancy\ClaimScopeCheck
 * @uses   \OCA\Portaliq\Event\PortalClaimScopeEvent
 */
class PortalAccountsByClaimOrganisationTest extends TestCase {
	use TenantHarness;

	private ActingObjectService $objectService;

	protected function setUp(): void {
		$this->objectService = new ActingObjectService();
		$this->objectService->rows = [
			'portalAccount' => [
				['id' => 'acc-1', 'subjectRef' => 's1', 'status' => 'active', 'organisation' => 'school-a', 'claims' => ['learniq' => ['guardianRef' => 'g-1']]],
			],
		];
	}//end setUp()

	public function testTheAccountsAreListedActingForTheRecordsOrganisation(): void {
		$accounts = $this->accounts()->find(appId: 'learniq', claim: 'guardianRef', value: 'g-1', organisation: 'uuid-school-a');

		$this->assertSame(['acc-1'], array_column($accounts, 'id'));
		$this->assertSame(['uuid-school-a'], $this->objectService->actedFor(method: 'findAll'));
	}//end testTheAccountsAreListedActingForTheRecordsOrganisation()

	public function testARecordWithoutAnOrganisationListsAsBefore(): void {
		$this->accounts()->find(appId: 'learniq', claim: 'guardianRef', value: 'g-1');

		$this->assertSame([null], $this->objectService->actedFor(method: 'findAll'));
	}//end testARecordWithoutAnOrganisationListsAsBefore()

	public function testEachOrganisationGetsItsOwnListing(): void {
		$accounts = $this->accounts();

		$accounts->find(appId: 'learniq', claim: 'guardianRef', value: 'g-1', organisation: 'uuid-school-a');
		$accounts->find(appId: 'learniq', claim: 'guardianRef', value: 'g-1', organisation: 'uuid-school-a');
		$accounts->find(appId: 'learniq', claim: 'guardianRef', value: 'g-1', organisation: 'uuid-school-b');

		$this->assertSame(['uuid-school-a', 'uuid-school-b'], $this->objectService->actedFor(method: 'findAll'));
	}//end testEachOrganisationGetsItsOwnListing()

	/**
	 * The listing over the acting object service.
	 *
	 * @return PortalAccountsByClaim
	 */
	private function accounts(): PortalAccountsByClaim {
		return new PortalAccountsByClaim(container: $this->tenantContainer(objectService: $this->objectService), logger: new NullLogger());
	}//end accounts()
}//end class
