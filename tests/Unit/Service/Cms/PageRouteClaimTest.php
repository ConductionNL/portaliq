<?php

/**
 * A page may not take a route another page of its portal already holds in
 * the same language (portal-cms-admin-ui).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PageRouteClaim;
use OCA\Portaliq\Service\RowIdLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageRouteClaim::class)]
#[UsesClass(RowIdLookup::class)]
class PageRouteClaimTest extends TestCase {

	/**
	 * The portal's stored pages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored(): array {
		return [
			['id' => 'p-home', 'route' => '/', 'status' => 'published'],
			['id' => 'p-about', 'route' => '/over-ons', 'status' => 'published', 'locale' => 'nl'],
			['@self' => ['id' => 'p-terms'], 'route' => '/begrippen/', 'status' => 'draft'],
		];
	}

	/**
	 * A second page on a route is refused, naming the route, drafts included.
	 *
	 * @return void
	 */
	public function testASecondPageOnARouteIsADuplicate(): void {
		$claim = new PageRouteClaim();

		$this->assertSame('/over-ons', $claim->duplicate(['route' => '/over-ons', 'locale' => 'NL '], '', self::stored()));
		$this->assertSame('/begrippen', $claim->duplicate(['route' => '/begrippen?x=1#top'], 'p-new', self::stored()), 'the route is compared as pages are keyed');
		$this->assertSame('/', $claim->duplicate(['route' => '/'], '', self::stored()));
	}

	/**
	 * The page itself, saved again, is no duplicate of its own stored row.
	 *
	 * @return void
	 */
	public function testThePageItselfIsNoDuplicate(): void {
		$claim = new PageRouteClaim();

		$this->assertSame('', $claim->duplicate(['route' => '/over-ons', 'locale' => 'nl'], 'p-about', self::stored()));
		$this->assertSame('', $claim->duplicate(['route' => '/begrippen'], 'p-terms', self::stored()), 'the id inside @self counts too');
	}

	/**
	 * A translation shares its route in another language.
	 *
	 * @return void
	 */
	public function testATranslationIsNoDuplicate(): void {
		$claim = new PageRouteClaim();

		$this->assertSame('', $claim->duplicate(['route' => '/over-ons', 'locale' => 'en'], '', self::stored()));
		$this->assertSame('', $claim->duplicate(['route' => '/over-ons'], '', self::stored()), 'no locale is not nl');
	}

	/**
	 * A free route, or one that is no in-site route, claims nothing.
	 *
	 * @return void
	 */
	public function testAFreeOrForeignRouteClaimsNothing(): void {
		$claim = new PageRouteClaim();

		$this->assertSame('', $claim->duplicate(['route' => '/contact'], '', self::stored()));
		foreach (['', 'over-ons', '//evil.example/over-ons', null, 7] as $route) {
			$this->assertSame('', $claim->duplicate(['route' => $route], '', self::stored()));
		}

		$this->assertSame('', $claim->duplicate(['route' => '/over-ons', 'locale' => 'nl'], '', ['junk', ['route' => 5]]));
	}
}
