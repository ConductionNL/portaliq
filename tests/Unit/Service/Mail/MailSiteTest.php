<?php

/**
 * The portal, name and language a tenant's mail is sent with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Mail;

use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MailSite::class)]
class MailSiteTest extends TestCase {
	/**
	 * The portal's record, or null when the lookup fails.
	 *
	 * @return void
	 */
	public function testThePortalOrNull(): void {
		$this->assertSame(['slug' => 'zuiddrecht'], $this->site(portal: ['slug' => 'zuiddrecht'])->portal('org-1'));
		$this->assertNull($this->site(portalFails: true)->portal('org-1'));
	}//end testThePortalOrNull()

	/**
	 * The portal's title, else the organisation's name, else Portaliq.
	 *
	 * @return void
	 */
	public function testTheNameFallsBackInOrder(): void {
		$this->assertSame('Gemeente Zuiddrecht', $this->site()->name(['title' => ' Gemeente Zuiddrecht '], 'org-1'));
		$this->assertSame('Organisatie X', $this->site(organisationName: 'Organisatie X')->name(['title' => ''], 'org-1'));
		$this->assertSame('Portaliq', $this->site(organisationName: '')->name(null, 'org-1'));
		$this->assertSame('Portaliq', $this->site(organisationFails: true)->name(null, 'org-1'));
	}//end testTheNameFallsBackInOrder()

	/**
	 * The portal's first language, else Dutch.
	 *
	 * @return void
	 */
	public function testTheLanguageIsThePortalsFirst(): void {
		$site = $this->site();
		$this->assertSame('en', $site->language(['locales' => ['en', 'nl']]));
		$this->assertSame('nl', $site->language(['locales' => [' ']]));
		$this->assertSame('nl', $site->language(['locales' => 'en']));
		$this->assertSame('nl', $site->language(null));
	}//end testTheLanguageIsThePortalsFirst()

	/**
	 * A MailSite over doubles.
	 *
	 * @param array<string, mixed>|null $portal            The portal the resolver answers.
	 * @param bool                      $portalFails       Whether the resolver throws.
	 * @param string                    $organisationName  The organisation's name.
	 * @param bool                      $organisationFails Whether the organisation lookup throws.
	 *
	 * @return MailSite
	 */
	private function site(?array $portal = null, bool $portalFails = false, string $organisationName = 'Organisatie X', bool $organisationFails = false): MailSite {
		$portals = $this->getMockBuilder(PortalResolver::class)->disableOriginalConstructor()->onlyMethods(['resolveByOrganisation'])->getMock();
		if ($portalFails === true) {
			$portals->method('resolveByOrganisation')->willThrowException(new RuntimeException('no portal'));
		} else {
			$portals->method('resolveByOrganisation')->willReturn($portal);
		}

		$organisations = $this->getMockBuilder(PortalOrganisationConfigService::class)->disableOriginalConstructor()->onlyMethods(['resolve'])->getMock();
		if ($organisationFails === true) {
			$organisations->method('resolve')->willThrowException(new RuntimeException('no organisation'));
		} else {
			$organisations->method('resolve')->willReturn(['organisationName' => $organisationName]);
		}

		return new MailSite($portals, $organisations);
	}//end site()
}//end class
