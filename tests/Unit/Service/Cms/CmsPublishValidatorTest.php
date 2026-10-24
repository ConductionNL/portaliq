<?php

/**
 * What would render broken if a portal's content went out as it is
 * (portal-cms-admin-ui).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\CmsPublishValidator;
use PHPUnit\Framework\TestCase;

class CmsPublishValidatorTest extends TestCase {

	private static function pages(): array {
		return [
			['route' => '/', 'status' => 'published'],
			['route' => '/over-ons', 'status' => 'published'],
			['route' => '/begrippen', 'status' => 'draft'],
		];
	}

	public function testAMenuItemPointingAtNoPageIsRefusedNamingTheRoute(): void {
		$menus = [['items' => [['name' => 'Over ons', 'link' => '/over-ons'], ['name' => 'Begrippen', 'link' => '/woordenlijst']]]];

		$found = (new CmsPublishValidator())->check(self::pages(), $menus);

		$this->assertSame([['code' => 'menu-link-no-page', 'route' => '/woordenlijst']], $found['blocking']);
	}

	public function testAMenuItemPointingAtADraftPageOnlyWarns(): void {
		$menus = [['items' => [['name' => 'Begrippen', 'link' => '/begrippen']]]];

		$found = (new CmsPublishValidator())->check(self::pages(), $menus);

		$this->assertSame([], $found['blocking']);
		$this->assertSame([['code' => 'menu-link-draft-page', 'route' => '/begrippen']], $found['warnings']);
	}

	public function testAPortalWithNoPublishedPageAtTheRootIsRefused(): void {
		$none  = (new CmsPublishValidator())->check([['route' => '/over-ons', 'status' => 'published']], []);
		$draft = (new CmsPublishValidator())->check([['route' => '/', 'status' => 'draft']], []);

		$this->assertSame([['code' => 'no-root-page', 'route' => '/']], $none['blocking']);
		$this->assertSame([['code' => 'no-root-page', 'route' => '/']], $draft['blocking'], 'a draft front page is no front door');
	}

	public function testDuplicateRoutesWithinOnePortalAreRefused(): void {
		$pages   = self::pages();
		$pages[] = ['route' => '/over-ons/', 'status' => 'draft'];

		$found = (new CmsPublishValidator())->check($pages, []);

		$this->assertSame([['code' => 'duplicate-route', 'route' => '/over-ons']], $found['blocking']);
	}

	public function testSubItemsAndExternalLinksAreRead(): void {
		$menus = [['items' => [
			['name' => 'Extern', 'link' => 'https://example.org/x', 'items' => [['name' => 'Weg', 'link' => '/weg?a=1#b'], ['name' => 'Anker', 'link' => '#top']]],
			['name' => 'Zonder link'],
		]]];

		$found = (new CmsPublishValidator())->check(self::pages(), $menus);

		$this->assertSame([['code' => 'menu-link-no-page', 'route' => '/weg']], $found['blocking'], 'a sub-item is checked; an outside address and an anchor are not');
	}

	public function testAHealthyPortalPassesClean(): void {
		$found = (new CmsPublishValidator())->check(self::pages(), [['items' => [['name' => 'Over ons', 'link' => '/over-ons']]]]);

		$this->assertSame(['blocking' => [], 'warnings' => []], $found);
	}
}
