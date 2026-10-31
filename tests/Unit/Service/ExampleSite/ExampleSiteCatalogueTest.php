<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleSite;

use OCA\Portaliq\Service\ExampleSite\ExampleSiteCatalogue;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * site-member-voting-record-and-confidential-papers REQ-SCR-005: the voting
 * record page is installed with Zuiddrecht only when decidiq is installed.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t7
 */
class ExampleSiteCatalogueTest extends TestCase {

	private const ROUTE = '/bestuur/hoe-stemden-de-raadsleden';

	/**
	 * The routes of Zuiddrecht's pages for an instance with the given apps.
	 *
	 * @param IAppManager|null $apps The app manager, or none.
	 *
	 * @return array<int, string>
	 */
	private function routes(?IAppManager $apps): array {
		$site = (new ExampleSiteCatalogue(directory: null, apps: $apps))->find(id: 'zuiddrecht');

		return array_column($site['pages'], 'route');
	}

	/**
	 * The Raadsleden page as installed, as text.
	 *
	 * @param IAppManager $apps The app manager.
	 *
	 * @return string
	 */
	private function linksOf(IAppManager $apps): string {
		$site = (new ExampleSiteCatalogue(directory: null, apps: $apps))->find(id: 'zuiddrecht');
		$page = array_values(array_filter($site['pages'], static fn (array $p): bool => $p['route'] === '/bestuur/raadsleden'))[0];

		return (string)json_encode($page, JSON_UNESCAPED_SLASHES);
	}

	public function testThePageIsInstalledWithDecidiq(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'decidiq');

		$this->assertContains(self::ROUTE, $this->routes($apps));
		$page = array_values(array_filter((new ExampleSiteCatalogue(directory: null, apps: $apps))->find(id: 'zuiddrecht')['pages'], static fn (array $p): bool => $p['route'] === self::ROUTE))[0];
		$this->assertArrayNotHasKey('requiresApp', $page, 'the key never reaches the store');
		$this->assertSame('publicRecords', $page['body']['widgets'][1]['widgetKey']);
		$this->assertSame('memberVotingRecords', $page['body']['widgets'][1]['props']['list']);

	}//end testThePageIsInstalledWithDecidiq()

	public function testWithoutDecidiqThePageIsNotInstalled(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(false);

		$this->assertNotContains(self::ROUTE, $this->routes($apps));
		$this->assertNotContains(self::ROUTE, $this->routes(null), 'no app manager means no app');
		$this->assertContains('/bestuur/raadsleden', $this->routes($apps), 'the other pages stay');
		$this->assertStringNotContainsString(self::ROUTE, $this->linksOf(apps: $apps), 'no link to a page that is not installed');
		$with = $this->createMock(IAppManager::class);
		$with->method('isInstalled')->willReturn(true);
		$this->assertStringContainsString(self::ROUTE, $this->linksOf(apps: $with), 'the Raadsleden page links it with decidiq');

	}//end testWithoutDecidiqThePageIsNotInstalled()

	/**
	 * The page with this route as installed, or null when it is not.
	 *
	 * @param IAppManager $apps  The app manager.
	 * @param string      $route The route.
	 *
	 * @return array<string, mixed>|null
	 */
	private function pageAt(IAppManager $apps, string $route): ?array {
		$site = (new ExampleSiteCatalogue(directory: null, apps: $apps))->find(id: 'zuiddrecht');
		foreach ($site['pages'] as $page) {
			if ($page['route'] === $route) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * The `{app, list}` of every public records block on a page.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return array<int, string>
	 */
	private static function recordLists(array $page): array {
		$lists = [];
		foreach ($page['body']['widgets'] as $widget) {
			if ($widget['widgetKey'] === 'publicRecords') {
				$lists[] = $widget['props']['app'].':'.$widget['props']['list'];
			}
		}

		return $lists;
	}

	/**
	 * With decidiq the meetings page lists decidiq's published meetings and the
	 * decisions page its published decisions, one block per page (site-council-pages-read-decidiq).
	 *
	 * @spec openspec/specs/site-council-records/spec.md#requirement-the-council-pages-show-decidiqs-published-meetings-and-decisions-req-scp-001
	 */
	public function testTheCouncilPagesReadDecidiqsPublishedRecords(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'decidiq');

		$this->assertSame(['decidiq:publicMeetings'], self::recordLists($this->pageAt(apps: $apps, route: '/bestuur/vergaderingen')));
		$this->assertSame(['decidiq:publicDecisions'], self::recordLists($this->pageAt(apps: $apps, route: '/bestuur/besluiten')));
		$this->assertSame(['decidiq:memberVotingRecords'], self::recordLists($this->pageAt(apps: $apps, route: self::ROUTE)));
		$this->assertStringNotContainsString('requiresApp', (string)json_encode($this->pageAt(apps: $apps, route: '/bestuur/vergaderingen')), 'the key never reaches the store');

	}//end testTheCouncilPagesReadDecidiqsPublishedRecords()

	/**
	 * Without decidiq the meetings page keeps its text but loses the block, and
	 * the decisions page and every link to it are not installed.
	 *
	 * @spec openspec/specs/site-council-records/spec.md#requirement-the-council-pages-show-decidiqs-published-meetings-and-decisions-req-scp-001
	 */
	public function testWithoutDecidiqTheCouncilPagesKeepTheirText(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(false);

		$meetings = $this->pageAt(apps: $apps, route: '/bestuur/vergaderingen');
		$this->assertNotNull($meetings);
		$this->assertSame([], self::recordLists($meetings));
		$this->assertSame('nlParagraph', $meetings['body']['widgets'][0]['widgetKey']);
		$this->assertNull($this->pageAt(apps: $apps, route: '/bestuur/besluiten'));
		$site = (string)json_encode((new ExampleSiteCatalogue(directory: null, apps: $apps))->find(id: 'zuiddrecht'), JSON_UNESCAPED_SLASHES);
		$this->assertStringNotContainsString('/bestuur/besluiten', $site, 'no link to a page that is not installed');

	}//end testWithoutDecidiqTheCouncilPagesKeepTheirText()
}//end class
