<?php

/**
 * The edges of the publish check: pages without a usable route, menu items
 * that are not items, and links that point off the portal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\CmsPublishValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Cms\CmsPublishValidator
 */
class CmsPublishValidatorEdgeTest extends TestCase {

	public function testPagesWithoutALocalRouteAreLeftOutOfTheCheck(): void {
		$result = (new CmsPublishValidator())->check(
			[
				['route' => '/', 'status' => 'published'],
				['route' => '', 'status' => 'published'],
				['route' => 'https://elders.example/x', 'status' => 'published'],
				['route' => '//cdn.example/x', 'status' => 'published'],
				['status' => 'draft'],
			],
			[]
		);

		$this->assertSame(['blocking' => [], 'warnings' => []], $result);
	}

	public function testMenuEntriesThatAreNotItemsOrLeaveThePortalAreSkipped(): void {
		$result = (new CmsPublishValidator())->check(
			[['route' => '/', 'status' => 'published'], ['route' => '/nieuws/', 'status' => 'draft']],
			[
				['items' => ['not an item', 42, ['link' => 'https://elders.example'], ['link' => ''], ['link' => '/nieuws?pagina=2#top']]],
				['items' => 'broken'],
				['name' => 'without items'],
			]
		);

		$this->assertSame([], $result['blocking']);
		$this->assertSame([['code' => CmsPublishValidator::MENU_LINK_DRAFT_PAGE, 'route' => '/nieuws']], $result['warnings']);
	}
}
