<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\CmsReader;
use PHPUnit\Framework\TestCase;

/**
 * site-page-seo-history-and-media REQ-SPH-001 and REQ-SPH-002: the served
 * head carries a page's search title and description, falls back to its title
 * and summary, honours noindex, and a route with no published page lends
 * nothing to the head.
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */
class SiteHeadTest extends TestCase {

	private const PORTAL = ['slug' => 'gemeente', 'title' => 'Gemeente Voorbeeld'];

	public function testTheSearchTitleAndDescriptionAreServed(): void {
		$head = $this->head(page: [
			'title' => 'Afval',
			'summary' => 'Samenvatting',
			'seo' => ['title' => 'Afval en recycling', 'description' => 'Opening hours of the town hall'],
		])->for(portal: self::PORTAL, route: '/contact', locale: 'nl', canonical: 'https://example.nl/contact');

		$this->assertSame('Afval en recycling - Gemeente Voorbeeld', $head['title']);
		$this->assertSame('Opening hours of the town hall', $head['description']);
		$this->assertSame('index, follow', $head['robots']);
		$this->assertSame('https://example.nl/contact', $head['canonical']);
	}//end testTheSearchTitleAndDescriptionAreServed()

	public function testTheTitleAndSummaryAreTheFallback(): void {
		$head = $this->head(page: ['title' => 'Contact', 'summary' => 'Bel ons of kom langs.'])
			->for(portal: self::PORTAL, route: '/contact', locale: 'nl', canonical: '');

		$this->assertSame('Contact - Gemeente Voorbeeld', $head['title']);
		$this->assertSame('Bel ons of kom langs.', $head['description']);
	}//end testTheTitleAndSummaryAreTheFallback()

	public function testAPageKeptOutOfSearchEnginesIsNoindex(): void {
		$head = $this->head(page: ['title' => 'Intern', 'seo' => ['noindex' => true]])
			->for(portal: self::PORTAL, route: '/intern', locale: 'nl', canonical: '');

		$this->assertSame('noindex', $head['robots']);
	}//end testAPageKeptOutOfSearchEnginesIsNoindex()

	public function testARouteWithoutAPublishedPageLendsNothing(): void {
		$head = $this->head(page: null)->for(portal: self::PORTAL, route: '/concept', locale: 'nl', canonical: 'https://example.nl/concept');

		$this->assertSame(['title' => 'Gemeente Voorbeeld', 'description' => '', 'robots' => 'noindex', 'canonical' => '', 'ogImage' => ''], $head);
	}//end testARouteWithoutAPublishedPageLendsNothing()

	public function testThePageIsReadAsTheAnonymousPublicReadsIt(): void {
		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page'])->getMock();
		$reader->expects($this->once())
			->method('page')
			->with($this->equalTo('gemeente'), $this->equalTo('/contact'), $this->equalTo('nl'), $this->equalTo('anonymous'))
			->willReturn(null);

		(new SiteHead($reader))->for(portal: self::PORTAL, route: 'contact/', locale: 'nl', canonical: '');
	}//end testThePageIsReadAsTheAnonymousPublicReadsIt()

	public function testOnlyAnAbsoluteImageAddressBecomesTheShareImage(): void {
		$absolute = $this->head(page: ['title' => 'A', 'seo' => ['image' => 'https://example.nl/a.jpg']])
			->for(portal: self::PORTAL, route: '/a', locale: 'nl', canonical: '');
		$relative = $this->head(page: ['title' => 'B', 'seo' => ['image' => 'javascript:alert(1)']])
			->for(portal: self::PORTAL, route: '/b', locale: 'nl', canonical: '');

		$this->assertSame('https://example.nl/a.jpg', $absolute['ogImage']);
		$this->assertSame('', $relative['ogImage']);
	}//end testOnlyAnAbsoluteImageAddressBecomesTheShareImage()

	public function testNoPortalMeansNoHead(): void {
		$this->assertSame('', $this->head(page: ['title' => 'X'])->for(portal: null, route: '/', locale: 'nl', canonical: '')['title']);
	}//end testNoPortalMeansNoHead()

	/**
	 * The head over a reader that answers one page.
	 *
	 * @param array<string, mixed>|null $page The published page, or null.
	 *
	 * @return SiteHead
	 */
	private function head(?array $page): SiteHead {
		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page'])->getMock();
		$reader->method('page')->willReturn($page);

		return new SiteHead($reader);
	}//end head()

}//end class
