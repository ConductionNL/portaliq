<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\SiteCreator;
use PHPUnit\Framework\TestCase;

/**
 * The head's DCTERMS.creator names the organisation and its kind (REQ-PIA-003).
 *
 * @covers \OCA\Portaliq\Service\Cms\SiteCreator
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */
final class SiteCreatorTest extends TestCase {

	public function testTheOrganisationIsNamedWithItsType(): void {
		self::assertSame(
			'Hoogheemraadschap Noord (waterschap)',
			SiteCreator::content(portal: ['title' => 'Mijn Noord', 'organisationTypeLabel' => ' waterschap '], organisation: ['id' => 'o1', 'name' => 'Hoogheemraadschap Noord'])
		);
	}//end testTheOrganisationIsNamedWithItsType()

	public function testWithoutAnOrganisationThePortalTitleStands(): void {
		self::assertSame('Mijn Noord (waterschap)', SiteCreator::content(portal: ['title' => 'Mijn Noord', 'organisationTypeLabel' => 'waterschap'], organisation: null));
		self::assertSame('Mijn Noord', SiteCreator::content(portal: ['title' => 'Mijn Noord'], organisation: ['id' => 'o1', 'name' => '  ']));
	}//end testWithoutAnOrganisationThePortalTitleStands()

	public function testNoNameMeansNoCreator(): void {
		self::assertSame('', SiteCreator::content(portal: ['organisationTypeLabel' => 'waterschap'], organisation: null));
	}//end testNoNameMeansNoCreator()
}//end class
