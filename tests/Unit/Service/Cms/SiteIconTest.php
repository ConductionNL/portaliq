<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\Cms\SiteIcon;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-from-the-admin REQ-PIA-002: the tab icon is the portal's
 * favicon, then its logo, then the theme's icon, then portaliq's own mark,
 * and a media reference becomes the item's public address.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */
#[CoversClass(SiteIcon::class)]
#[UsesClass(MediaReferences::class)]
class SiteIconTest extends TestCase {

	/**
	 * The published library items of portal `zuid`, by id.
	 *
	 * @var array<string, array{id: string, title: string, alt: string, kind: string}>
	 */
	private const ITEMS = [
		'fav' => ['id' => 'fav', 'title' => 'Favicon', 'alt' => 'Wapen', 'kind' => 'image'],
		'logo' => ['id' => 'logo', 'title' => 'Logo', 'alt' => 'Logo', 'kind' => 'image'],
	];

	public function testTheFaviconWinsOverTheLogo(): void {
		$icon = $this->icon()->forPortal(portal: ['slug' => 'zuid', 'favicon' => 'media:fav', 'logo' => 'media:logo']);

		$this->assertSame('https://zuid.nl/apps/portaliq/api/content/media/fav?portal=zuid', $icon);
	}//end testTheFaviconWinsOverTheLogo()

	public function testAMediaReferenceBecomesAPublicUrl(): void {
		$icon = $this->icon()->forPortal(portal: ['slug' => 'zuid', 'logo' => 'media:logo']);

		$this->assertSame('https://zuid.nl/apps/portaliq/api/content/media/logo?portal=zuid', $icon);
	}//end testAMediaReferenceBecomesAPublicUrl()

	public function testAnAddressLogoIsKeptAsItIs(): void {
		$this->assertSame('/apps/portaliq/logo/zuid.svg', $this->icon()->forPortal(portal: ['slug' => 'zuid', 'logo' => '/apps/portaliq/logo/zuid.svg']));
		$this->assertSame('https://cdn.zuid.nl/logo.svg', $this->icon()->forPortal(portal: ['slug' => 'zuid', 'logo' => 'https://cdn.zuid.nl/logo.svg']));
	}//end testAnAddressLogoIsKeptAsItIs()

	public function testAnUnresolvedFaviconFallsToTheLogo(): void {
		// A draft item, or another portal's, is not among the published items.
		$icon = $this->icon()->forPortal(portal: ['slug' => 'zuid', 'favicon' => 'media:draft', 'logo' => 'media:logo']);

		$this->assertSame('https://zuid.nl/apps/portaliq/api/content/media/logo?portal=zuid', $icon);
	}//end testAnUnresolvedFaviconFallsToTheLogo()

	public function testAValueThatIsNoAddressIsNeverEmitted(): void {
		$this->assertSame('', $this->icon()->forPortal(portal: ['slug' => 'zuid', 'favicon' => 'javascript:alert(1)', 'logo' => '//evil.example/x.svg']));
		$this->assertSame('', $this->icon()->forPortal(portal: ['slug' => 'zuid', 'favicon' => ['media:fav'], 'logo' => '   ']));
		$this->assertSame('', $this->icon()->forPortal(portal: null));
	}//end testAValueThatIsNoAddressIsNeverEmitted()

	public function testNothingSetFallsBackToTheThemeThenTheOwnMark(): void {
		$portalIcon = $this->icon()->forPortal(portal: ['slug' => 'zuid']);

		$this->assertSame('', $portalIcon);
		$this->assertSame('/apps/nldesign/img/logos/zuid.svg', SiteIcon::first($portalIcon, '/apps/nldesign/img/logos/zuid.svg', '/apps/portaliq/img/app.svg'));
		$this->assertSame('/apps/portaliq/img/app.svg', SiteIcon::first($portalIcon, '', '/apps/portaliq/img/app.svg'));
		$this->assertSame('/p.svg', SiteIcon::first('/p.svg', '/apps/nldesign/img/logos/zuid.svg', '/apps/portaliq/img/app.svg'));
	}//end testNothingSetFallsBackToTheThemeThenTheOwnMark()

	/**
	 * A SiteIcon over portal `zuid`'s published items.
	 *
	 * @return SiteIcon
	 */
	private function icon(): SiteIcon {
		$library = $this->createMock(MediaLibraryReader::class);
		$library->method('item')->willReturnCallback(
			static fn (string $portal, string $id): ?array => ($portal === 'zuid' ? (self::ITEMS[$id] ?? null) : null)
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route, array $params): string {
				self::assertSame('portaliq.contentMedia.show', $route);
				return 'https://' . $params['portal'] . '.nl/apps/portaliq/api/content/media/' . $params['id'] . '?portal=' . $params['portal'];
			}
		);

		return new SiteIcon(media: new MediaReferences(urls: $urls, library: $library));
	}//end icon()
}//end class
