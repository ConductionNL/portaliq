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
}//end class
