<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\Cms\SiteIcon;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The tab icon: the portal's favicon, then its logo, then the theme's icon,
 * then portaliq's own mark (portal-identity-from-the-admin REQ-PIA-002).
 *
 * MediaReferences is the real class; only the library reader and the URL
 * generator are doubles.
 *
 * @covers \OCA\Portaliq\Service\Cms\SiteIcon
 * @uses   \OCA\Portaliq\Service\Cms\MediaReferences
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */
class SiteIconTest extends TestCase {

	public function testTheFaviconWinsOverTheLogo(): void {
		$url = $this->icon()->url(
			portal: ['slug' => 'gemeente', 'favicon' => 'media:fav', 'logo' => 'media:logo'],
			themeIcon: '/apps/nldesign/img/logos/zuid.svg'
		);

		$this->assertSame('https://site.example/api/content/media/fav?portal=gemeente', $url);
	}//end testTheFaviconWinsOverTheLogo()

	public function testAMediaReferenceBecomesAPublicUrl(): void {
		$icon = $this->icon();

		$this->assertSame(
			'https://site.example/api/content/media/logo?portal=gemeente',
			$icon->url(portal: ['slug' => 'gemeente', 'logo' => 'media:logo'], themeIcon: '')
		);
		// A favicon of another portal or a draft does not resolve, so the logo answers.
		$this->assertSame(
			'https://site.example/api/content/media/logo?portal=gemeente',
			$icon->url(portal: ['slug' => 'gemeente', 'favicon' => 'media:elsewhere', 'logo' => 'media:logo'], themeIcon: '')
		);
		// A logo given as a web address is used as it is.
		$this->assertSame(
			'https://cdn.example/logo.svg',
			$icon->url(portal: ['slug' => 'gemeente', 'logo' => 'https://cdn.example/logo.svg'], themeIcon: '')
		);
	}//end testAMediaReferenceBecomesAPublicUrl()

	public function testNothingSetFallsBackToTheThemeThenTheOwnMark(): void {
		$icon = $this->icon();

		$this->assertSame('/apps/nldesign/img/logos/zuid.svg', $icon->url(portal: ['slug' => 'gemeente'], themeIcon: '/apps/nldesign/img/logos/zuid.svg'));
		$this->assertSame('/apps/portaliq/img/app.svg', $icon->url(portal: ['slug' => 'gemeente', 'logo' => 'javascript:alert(1)'], themeIcon: ''));
		$this->assertSame('/apps/portaliq/img/app.svg', $icon->url(portal: null, themeIcon: ''));
	}//end testNothingSetFallsBackToTheThemeThenTheOwnMark()

	/**
	 * The icon over a library holding `fav` and `logo` on gemeente.
	 *
	 * @return SiteIcon
	 */
	private function icon(): SiteIcon {
		$library = $this->getMockBuilder(MediaLibraryReader::class)->disableOriginalConstructor()->onlyMethods(['item'])->getMock();
		$library->method('item')->willReturnCallback(
			static fn (string $portal, string $id) => ($portal === 'gemeente' && in_array($id, ['fav', 'logo'], true) === true) ? ['id' => $id, 'title' => $id, 'alt' => '', 'kind' => 'image'] : null
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params) => 'https://site.example/api/content/media/'.$params['id'].'?portal='.$params['portal']
		);
		$urls->method('linkTo')->willReturnCallback(static fn (string $app, string $file) => '/apps/'.$app.'/'.$file);

		return new SiteIcon(new MediaReferences($urls, $library), $urls);
	}//end icon()
}//end class
