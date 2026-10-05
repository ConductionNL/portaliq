<?php

/**
 * Unit tests for ExampleSiteInstaller and ExampleSiteCatalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleSite;

use OCA\Portaliq\Service\ExampleSite\ExampleSiteCatalogue;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteInstaller;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The installer runs here against the SHIPPED Zuiddrecht declaration and a
 * store that behaves like OpenRegister where it matters: a write is answered
 * with an id whatever happens to the row, a key the schema does not know is
 * not kept, and a read filters on a property.
 */
class ExampleSiteInstallerTest extends TestCase {
	/**
	 * The rows the fake store holds, per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Top-level keys the fake store does not keep, per schema.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $unknownKeys = [];

	/**
	 * Page routes the fake store refuses to write.
	 *
	 * @var array<int, string>
	 */
	private array $refusedRoutes = [];

	/**
	 * Whether the fake store hands over every row, whatever the filter.
	 *
	 * @var bool
	 */
	private bool $ignoreFilters = false;

	/**
	 * The fake app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Start every test on an empty instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = ['portal' => [], 'menu' => [], 'page' => [], 'newsItem' => []];
		$this->unknownKeys = [];
		$this->refusedRoutes = [];
		$this->ignoreFilters = false;
		$this->config = [];
	}//end setUp()

	/**
	 * A fresh instance gets everything the declaration holds, and the
	 * counts are the declaration's own.
	 *
	 * @return void
	 */
	public function testAFreshInstanceGetsTheWholeSite(): void {
		$site = $this->zuiddrecht();
		$report = $this->installer()->install(site: $site);

		$this->assertTrue($report['ok'], implode("\n", array_merge($report['missing'], $report['lost'])));
		$this->assertSame(['declared' => 1, 'created' => 1, 'kept' => 0, 'arrived' => 1], $report['types']['portal']);
		$this->assertSame(['declared' => 3, 'created' => 3, 'kept' => 0, 'arrived' => 3], $report['types']['menu']);
		$this->assertSame(['declared' => 33, 'created' => 33, 'kept' => 0, 'arrived' => 33], $report['types']['page']);
		$this->assertSame(['declared' => 4, 'created' => 4, 'kept' => 0, 'arrived' => 4], $report['types']['newsItem']);

		// Every row belongs to the portal by its SLUG, which is what the
		// content reader filters on.
		foreach (['menu', 'page', 'newsItem'] as $schema) {
			foreach ($this->rows[$schema] as $row) {
				$this->assertSame('zuiddrecht', $row['portal']);
			}
		}

		$record = json_decode($this->config['example_site_zuiddrecht'], true);
		$this->assertCount(33, $record['page']);
		$this->assertCount(3, $record['menu']);
		$this->assertCount(4, $record['newsItem']);
		$this->assertNotSame('', $record['portal']);
	}//end testAFreshInstanceGetsTheWholeSite()

	/**
	 * A second run writes nothing, and what an editor changed stays.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$site = $this->zuiddrecht();
		$installer = $this->installer();
		$installer->install(site: $site);

		$homeId = $this->idOfPage(route: '/');
		$this->rows['page'][$homeId]['title'] = 'Welkom in Zuiddrecht';
		$before = $this->rows;

		$report = $installer->install(site: $site);

		$this->assertSame($before, $this->rows);
		$this->assertSame(0, $report['types']['page']['created']);
		$this->assertSame(33, $report['types']['page']['kept']);
		$this->assertSame(1, $report['types']['portal']['kept']);
		// The edited page is kept and not held against the declaration.
		$this->assertTrue($report['ok']);
	}//end testASecondRunChangesNothing()

	/**
	 * A register that does not know a portal key: the write is answered, the
	 * key is not kept, and the report names it.
	 *
	 * @return void
	 */
	public function testAKeyTheRegisterDropsIsNamed(): void {
		$this->unknownKeys = ['portal' => ['headerSearch', 'accountLabel']];

		$report = $this->installer()->install(site: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertSame(1, $report['types']['portal']['arrived']);
		$this->assertContains('portal zuiddrecht: headerSearch', $report['lost']);
		$this->assertContains('portal zuiddrecht: accountLabel', $report['lost']);
		$this->assertSame([], $report['missing']);
	}//end testAKeyTheRegisterDropsIsNamed()

	/**
	 * A page that does not arrive is counted and named.
	 *
	 * @return void
	 */
	public function testAPageThatDoesNotArriveIsNamed(): void {
		$this->refusedRoutes = ['/afval'];

		$report = $this->installer()->install(site: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertSame(33, $report['types']['page']['declared']);
		$this->assertSame(32, $report['types']['page']['created']);
		$this->assertSame(32, $report['types']['page']['arrived']);
		$this->assertSame(['page /afval'], $report['missing']);
	}//end testAPageThatDoesNotArriveIsNamed()

	/**
	 * A key lost on a page or a news item is named with the object it was
	 * lost on.
	 *
	 * @return void
	 */
	public function testAKeyLostOnAPageIsNamed(): void {
		$this->unknownKeys = ['page' => ['summary'], 'newsItem' => ['public']];

		$report = $this->installer()->install(site: $this->zuiddrecht());

		$this->assertFalse($report['ok']);
		$this->assertContains('page /afval: summary', $report['lost']);
		$this->assertContains('newsItem Nieuwe afvalkalender voor 2027: public', $report['lost']);
		$this->assertCount(37, $report['lost']);
	}//end testAKeyLostOnAPageIsNamed()

	/**
	 * A store that ignores the portal filter must not make another portal's
	 * page count as this site's.
	 *
	 * @return void
	 */
	public function testAnotherPortalsPageIsNotThisSites(): void {
		$this->ignoreFilters = true;
		$this->rows['page']['other'] = ['id' => 'other', 'portal' => 'open-tilburg', 'route' => '/afval', 'title' => 'Afval'];

		$report = $this->installer()->install(site: $this->zuiddrecht());

		$this->assertSame(33, $report['types']['page']['created']);
		$this->assertTrue($report['ok']);
	}//end testAnotherPortalsPageIsNotThisSites()

	/**
	 * Without OpenRegister nothing is written and nothing is recorded.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$report = $this->installer(available: false)->install(site: $this->zuiddrecht());

		$this->assertFalse($report['available']);
		$this->assertFalse($report['ok']);
		$this->assertSame([], $this->config);
	}//end testWithoutOpenRegisterNothingHappens()

	/**
	 * Remove after install leaves the instance as it was.
	 *
	 * @return void
	 */
	public function testRemoveDeletesWhatTheInstallCreated(): void {
		$installer = $this->installer();
		$installer->install(site: $this->zuiddrecht());

		$report = $installer->remove(site: 'zuiddrecht', slug: 'zuiddrecht');

		$this->assertTrue($report['recorded']);
		$this->assertSame(['newsItem' => 4, 'page' => 33, 'menu' => 3], $report['deleted']);
		$this->assertSame('deleted', $report['portal']);
		$this->assertSame([], $report['failed']);
		$this->assertSame(['portal' => [], 'menu' => [], 'page' => [], 'newsItem' => []], $this->rows);
		$this->assertArrayNotHasKey('example_site_zuiddrecht', $this->config);
	}//end testRemoveDeletesWhatTheInstallCreated()

	/**
	 * A page the organisation added stays, and so does the portal around it.
	 *
	 * @return void
	 */
	public function testRemoveKeepsContentThatIsNotFromTheSite(): void {
		$installer = $this->installer();
		$installer->install(site: $this->zuiddrecht());
		$this->rows['page']['own-page'] = ['id' => 'own-page', 'portal' => 'zuiddrecht', 'route' => '/eigen', 'title' => 'Eigen pagina'];
		// Another portal's page must never be touched or counted.
		$this->rows['page']['other'] = ['id' => 'other', 'portal' => 'open-tilburg', 'route' => '/afval', 'title' => 'Afval'];

		$report = $installer->remove(site: 'zuiddrecht', slug: 'zuiddrecht');

		$this->assertSame(33, $report['deleted']['page']);
		$this->assertSame('kept-content', $report['portal']);
		$this->assertSame(['own-page', 'other'], array_keys($this->rows['page']));
		$this->assertCount(1, $this->rows['portal']);

		// The record still names the portal, so a later run can finish.
		unset($this->rows['page']['own-page']);
		$again = $installer->remove(site: 'zuiddrecht', slug: 'zuiddrecht');
		$this->assertSame('deleted', $again['portal']);
		$this->assertSame(['other'], array_keys($this->rows['page']));
		$this->assertArrayNotHasKey('example_site_zuiddrecht', $this->config);
	}//end testRemoveKeepsContentThatIsNotFromTheSite()

	/**
	 * A portal that was there before the install is never deleted, and its
	 * own pages are kept as they were.
	 *
	 * @return void
	 */
	public function testAPortalThatWasThereBeforeIsKept(): void {
		$this->rows['portal']['mine'] = ['id' => 'mine', 'slug' => 'zuiddrecht', 'title' => 'Onze gemeente', 'status' => 'published'];
		$this->rows['page']['mine-home'] = ['id' => 'mine-home', 'portal' => 'zuiddrecht', 'route' => '/', 'title' => 'Onze home'];
		$installer = $this->installer();

		$report = $installer->install(site: $this->zuiddrecht());
		$this->assertSame(['declared' => 1, 'created' => 0, 'kept' => 1, 'arrived' => 1], $report['types']['portal']);
		$this->assertSame(32, $report['types']['page']['created']);
		$this->assertSame('Onze home', $this->rows['page']['mine-home']['title']);

		$removed = $installer->remove(site: 'zuiddrecht', slug: 'zuiddrecht');
		$this->assertSame('kept-not-ours', $removed['portal']);
		$this->assertSame(['mine'], array_keys($this->rows['portal']));
		$this->assertSame(['mine-home'], array_keys($this->rows['page']));
	}//end testAPortalThatWasThereBeforeIsKept()

	/**
	 * Remove without a record does nothing.
	 *
	 * @return void
	 */
	public function testRemoveWithoutARecordDoesNothing(): void {
		$this->rows['page']['p'] = ['id' => 'p', 'portal' => 'zuiddrecht', 'route' => '/afval'];

		$report = $this->installer()->remove(site: 'zuiddrecht', slug: 'zuiddrecht');

		$this->assertFalse($report['recorded']);
		$this->assertCount(1, $this->rows['page']);
	}//end testRemoveWithoutARecordDoesNothing()

	/**
	 * The comparison names nested keys, forgives a date in another zone's
	 * notation, and has nothing to say about an empty list.
	 *
	 * @return void
	 */
	public function testLostPaths(): void {
		$declared = [
			'title'       => 'Home',
			'domains'     => [],
			'position'    => 1,
			'publishedAt' => '2026-10-02T09:00:00+02:00',
			'footer'      => ['cta' => ['label' => 'Contact', 'href' => '/contact'], 'contact' => ['lines' => [['text' => 'a'], ['text' => 'b']]]],
		];
		$stored = [
			'title'       => 'Home',
			'position'    => '1',
			'publishedAt' => '2026-10-02T07:00:00+00:00',
			'footer'      => ['cta' => ['label' => 'Contact'], 'contact' => ['lines' => [['text' => 'a']]]],
			'extra'       => 'kept quiet',
		];

		$this->assertSame(
			['footer.cta.href', 'footer.contact.lines.1'],
			ExampleSiteInstaller::lostPaths(declared: $declared, stored: $stored)
		);
		$this->assertSame(['title'], ExampleSiteInstaller::lostPaths(declared: ['title' => 'Home'], stored: ['title' => 'Thuis']));
	}//end testLostPaths()

	/**
	 * The catalogue offers the shipped site and refuses what is not one.
	 *
	 * @return void
	 */
	public function testTheCatalogue(): void {
		$catalogue = new ExampleSiteCatalogue();
		$this->assertContains('zuiddrecht', $catalogue->ids());
		$this->assertNull($catalogue->find(id: '../portaliq_register'));
		$this->assertNull($catalogue->find(id: 'nergens'));

		$folder = sys_get_temp_dir() . '/portaliq-sites-' . bin2hex(random_bytes(4));
		mkdir($folder);
		file_put_contents($folder . '/goed.json', json_encode(['id' => 'goed', 'portal' => ['slug' => 'goed', 'title' => 'Goed']]));
		file_put_contents($folder . '/andere-naam.json', json_encode(['id' => 'goed', 'portal' => ['slug' => 'x', 'title' => 'X']]));
		file_put_contents($folder . '/zonder-portaal.json', json_encode(['id' => 'zonder-portaal']));
		file_put_contents($folder . '/kapot.json', '{');
		file_put_contents($folder . '/lijst.json', json_encode(['id' => 'lijst', 'portal' => ['slug' => 'l', 'title' => 'L'], 'pages' => ['a' => 1]]));

		$own = new ExampleSiteCatalogue(directory: $folder);
		$this->assertSame(['goed'], $own->ids());
		$this->assertSame(['menus' => [], 'pages' => [], 'news' => []], array_intersect_key($own->find(id: 'goed'), ['menus' => 1, 'pages' => 1, 'news' => 1]));

		array_map('unlink', (array)glob($folder . '/*.json'));
		rmdir($folder);
	}//end testTheCatalogue()

	/**
	 * The shipped declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function zuiddrecht(): array {
		$site = (new ExampleSiteCatalogue())->find(id: 'zuiddrecht');
		$this->assertNotNull($site);

		return $site;
	}//end zuiddrecht()

	/**
	 * The id of the stored page at a route of the Zuiddrecht portal.
	 *
	 * @param string $route The route.
	 *
	 * @return string
	 */
	private function idOfPage(string $route): string {
		foreach ($this->rows['page'] as $id => $row) {
			if ($row['route'] === $route && $row['portal'] === 'zuiddrecht') {
				return $id;
			}
		}

		$this->fail('No stored page at ' . $route);
	}//end idOfPage()

	/**
	 * An installer over the fake store and the fake app config.
	 *
	 * @param bool $available Whether the store can be reached.
	 *
	 * @return ExampleSiteInstaller
	 */
	private function installer(bool $available = true): ExampleSiteInstaller {
		$store = $this->createMock(ExampleSiteStore::class);
		$store->method('available')->willReturn($available);
		$store->method('find')->willReturnCallback(
			function (string $schema, array $filters): array {
				return array_values(
					array_filter(
						$this->rows[$schema],
						function (array $row) use ($filters): bool {
							if ($this->ignoreFilters === true) {
								return true;
							}

							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$store->method('create')->willReturnCallback(
			function (string $schema, array $data): ?string {
				if ($schema === 'page' && in_array($data['route'], $this->refusedRoutes, true) === true) {
					return null;
				}

				$id = $schema . '-' . (count($this->rows[$schema]) + 1) . '-' . bin2hex(random_bytes(3));
				foreach (($this->unknownKeys[$schema] ?? []) as $key) {
					unset($data[$key]);
				}

				$this->rows[$schema][$id] = (['id' => $id] + $data);

				return $id;
			}
		);
		$store->method('delete')->willReturnCallback(
			function (string $schema, string $id): bool {
				if (isset($this->rows[$schema][$id]) === false) {
					return false;
				}

				unset($this->rows[$schema][$id]);

				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;

				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		$themes = $this->createMock(PortalThemeResolver::class);
		$themes->method('stylesheetFor')->willReturn('tokens/zuiddrecht');

		return new ExampleSiteInstaller($store, $appConfig, $themes);
	}//end installer()
}//end class
