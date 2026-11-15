<?php

/**
 * Tests for PortalRegisteredDetailsLinks (identity-registered-details T06).
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsLinks;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PortalRegisteredDetailsLinksTest extends TestCase {

	private const PORTAL = [
		'slug' => 'mijn-gemeente',
		'registeredDetails' => [
			'correctionFormBinding' => 'binding-correction',
			'addressInvestigationFormBinding' => 'binding-address',
		],
	];

	public function testABoundAndPublishedFormBecomesALinkIntoThePortalSite(): void {
		$links = $this->links([
			['@self' => ['id' => 'binding-correction'], 'portal' => 'mijn-gemeente', 'route' => '/gegevens/correctie', 'status' => 'published'],
			['@self' => ['id' => 'binding-address'], 'portal' => 'mijn-gemeente', 'route' => '/adresonderzoek', 'status' => 'published'],
		]);

		$this->assertSame(
			[
				'correction' => '/apps/portaliq/site?portal=mijn-gemeente&route=%2Fgegevens%2Fcorrectie',
				'addressInvestigation' => '/apps/portaliq/site?portal=mijn-gemeente&route=%2Fadresonderzoek',
			],
			$links->forPortal(self::PORTAL, 'person')
		);

	}//end testABoundAndPublishedFormBecomesALinkIntoThePortalSite()

	public function testACompanyGetsNoAddressInvestigationLink(): void {
		$links = $this->links([
			['@self' => ['id' => 'binding-correction'], 'portal' => 'mijn-gemeente', 'route' => '/gegevens/correctie', 'status' => 'published'],
			['@self' => ['id' => 'binding-address'], 'portal' => 'mijn-gemeente', 'route' => '/adresonderzoek', 'status' => 'published'],
		]);

		$this->assertNull($links->forPortal(self::PORTAL, 'company')['addressInvestigation']);

	}//end testACompanyGetsNoAddressInvestigationLink()

	public function testAnUnboundOrUnpublishedFormGivesNoLink(): void {
		// publishedBindings() only answers published rows; binding-address is a draft.
		$links = $this->links([
			['@self' => ['id' => 'binding-other'], 'portal' => 'mijn-gemeente', 'route' => '/iets', 'status' => 'published'],
		]);

		$this->assertSame(['correction' => null, 'addressInvestigation' => null], $links->forPortal(self::PORTAL, 'person'));
		$this->assertSame(['correction' => null, 'addressInvestigation' => null], $links->forPortal(['slug' => 'mijn-gemeente'], 'person'));
		$this->assertSame(['correction' => null, 'addressInvestigation' => null], $links->forPortal(null, 'person'));

	}//end testAnUnboundOrUnpublishedFormGivesNoLink()

	/**
	 * The links service over a binding store answering the given rows.
	 *
	 * @param array<int, array<string, mixed>> $published The published bindings.
	 *
	 * @return PortalRegisteredDetailsLinks
	 */
	private function links(array $published): PortalRegisteredDetailsLinks {
		$bindings = $this->getMockBuilder(PortalFormBindingResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['publishedBindings'])
			->getMock();
		$bindings->method('publishedBindings')->willReturnCallback(
			static fn (string $portal): array => ($portal === 'mijn-gemeente' ? $published : [])
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => ($route === 'portaliq.portalPage.site' ? '/apps/portaliq/site?' . http_build_query($params) : '/wrong')
		);

		return new PortalRegisteredDetailsLinks($bindings, $urls);
	}//end links()
}//end class
