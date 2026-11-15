<?php

/**
 * How the search title is put together when the page and the portal share a
 * title, or the portal has none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\CmsReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Cms\SiteHead
 */
class SiteHeadEdgeTest extends TestCase {

	private function head(array $page): SiteHead {
		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page'])->getMock();
		$reader->expects($this->once())->method('page')->with('gemeente', '/contact', 'nl', 'anonymous')->willReturn($page);

		return new SiteHead($reader);
	}

	public function testAPageTitledLikeThePortalIsNotRepeated(): void {
		$head = $this->head(['title' => 'Gemeente Voorbeeld', 'seo' => ['noindex' => true, 'image' => 'ftp://x/y.png']])
			->for(portal: ['slug' => 'gemeente', 'title' => 'Gemeente Voorbeeld'], route: '/contact', locale: 'nl', canonical: 'https://example.nl/contact');

		$this->assertSame(
			['title' => 'Gemeente Voorbeeld', 'description' => '', 'robots' => 'noindex', 'canonical' => 'https://example.nl/contact', 'ogImage' => ''],
			$head
		);
	}

	public function testAPortalWithoutTitleShowsThePageTitleAlone(): void {
		$head = $this->head(['title' => 'Contact', 'summary' => ' Bel of mail ons. ', 'seo' => ['title' => '  ', 'image' => 'https://example.nl/og.png']])
			->for(portal: ['slug' => 'gemeente'], route: '/contact', locale: 'nl', canonical: '');

		$this->assertSame('Contact', $head['title']);
		$this->assertSame('Bel of mail ons.', $head['description']);
		$this->assertSame('index, follow', $head['robots']);
		$this->assertSame('https://example.nl/og.png', $head['ogImage']);
	}
}
