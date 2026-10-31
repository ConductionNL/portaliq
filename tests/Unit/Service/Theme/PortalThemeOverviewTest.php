<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\Theme\PortalThemeContrast;
use OCA\Portaliq\Service\Theme\PortalThemeOverview;
use OCA\Thematiq\Service\ContrastService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * themes-contrast-column: the Themes page lists every published portal with
 * its theme and the same contrast verdict a theme choice is checked with.
 * Built over the real resolver on a fixture theme app and the theme app's
 * real ContrastService.
 *
 * @spec openspec/specs/portal-themes/spec.md#requirement-the-themes-overview-says-per-portal-whether-its-theme-is-readable
 */
class PortalThemeOverviewTest extends TestCase {

	/**
	 * The fixture theme app.
	 *
	 * @var string
	 */
	private string $themeRoot = '';

	/**
	 * A theme app with a readable set, a hard-to-read one and a bare one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->themeRoot = sys_get_temp_dir() . '/pq-theme-overview-' . bin2hex(random_bytes(6));
		mkdir($this->themeRoot . '/css/tokens', 0o777, true);
		file_put_contents($this->themeRoot . '/css/tokens/readable.css', ":root {\n  --nldesign-color-background: #ffffff;\n  --nldesign-color-text: #1a1a1a;\n}\n");
		file_put_contents($this->themeRoot . '/css/tokens/faint.css', ":root {\n  --nldesign-color-background: #ffffff;\n  --nldesign-color-text: #cccccc;\n}\n");
		file_put_contents($this->themeRoot . '/css/tokens/bare.css', ":root {\n  --c-brand: #01689b;\n}\n");
		file_put_contents(
			$this->themeRoot . '/token-sets.json',
			(string)json_encode([
				['id' => 'readable', 'name' => 'Readable'],
				['id' => 'faint', 'name' => 'Faint'],
				['id' => 'bare', 'name' => 'Bare'],
			])
		);
	}//end setUp()

	/**
	 * Remove only what setUp created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->themeRoot . '/css/tokens/*.css') ?: [] as $file) {
			unlink($file);
		}

		@unlink($this->themeRoot . '/token-sets.json');
		@rmdir($this->themeRoot . '/css/tokens');
		@rmdir($this->themeRoot . '/css');
		@rmdir($this->themeRoot);
	}//end tearDown()

	/**
	 * A readable, a hard-to-read, an unmeasurable and an absent theme each get
	 * their verdict, the domain is the first hostname, and the poor ones are counted.
	 *
	 * @return void
	 */
	public function testEveryPortalGetsItsThemesVerdict(): void {
		$rows = $this->overview(portals: [
			['id' => 'p-1', 'slug' => 'gemeente', 'title' => 'Gemeente', 'theme' => 'readable', 'domains' => [['hostname' => 'gemeente.nl'], ['hostname' => 'www.gemeente.nl']]],
			['id' => 'p-2', 'slug' => 'mijn', 'title' => 'Mijn', 'theme' => 'faint'],
			['id' => 'p-3', 'slug' => 'kaal', 'title' => 'Kaal', 'theme' => 'bare'],
			['id' => 'p-4', 'slug' => 'geen', 'title' => 'Geen', 'theme' => ''],
			['id' => 'p-5', 'slug' => 'weg', 'title' => 'Weg', 'theme' => 'rotterdam'],
		], themeApp: true)->rows();

		$this->assertSame(['readable', 'poor', 'unchecked', 'unchecked', 'unchecked'], array_column($rows['portals'], 'contrast'));
		$this->assertSame(1, $rows['poorCount']);
		$this->assertSame('gemeente.nl', $rows['portals'][0]['domain']);
		$this->assertSame('', $rows['portals'][1]['domain']);
		$this->assertSame('Faint', $rows['portals'][1]['themeName']);
		$this->assertSame('rotterdam', $rows['portals'][4]['themeName']);
		$this->assertSame('change', $rows['portals'][0]['houseStyle']);
	}//end testEveryPortalGetsItsThemesVerdict()

	/**
	 * Without the theme app nothing is measured, so nothing reads as readable.
	 *
	 * @return void
	 */
	public function testWithoutTheThemeAppNothingReadsAsReadable(): void {
		$rows = $this->overview(portals: [['id' => 'p-1', 'slug' => 'gemeente', 'title' => 'Gemeente', 'theme' => 'readable']], themeApp: false)->rows();

		$this->assertSame('unchecked', $rows['portals'][0]['contrast']);
		$this->assertSame(0, $rows['poorCount']);
	}//end testWithoutTheThemeAppNothingReadsAsReadable()

	/**
	 * The overview over these portals and the fixture theme app.
	 *
	 * @param array<int, array<string, mixed>> $portals  The published portals.
	 * @param bool                             $themeApp Whether the theme app's contrast service answers.
	 *
	 * @return PortalThemeOverview
	 */
	private function overview(array $portals, bool $themeApp): PortalThemeOverview {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->willReturn($this->themeRoot);

		$container = $this->createMock(ContainerInterface::class);
		if ($themeApp === true) {
			$container->method('get')->willReturn(new ContrastService());
		} else {
			$container->method('has')->willReturn(false);
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$resolver = $this->getMockBuilder(PortalResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['allPublishedPortals'])
			->getMock();
		$resolver->method('allPublishedPortals')->willReturn($portals);

		return new PortalThemeOverview(
			$resolver,
			new PortalThemeResolver(appManager: $appManager),
			new PortalThemeContrast($container, $this->createMock(LoggerInterface::class))
		);
	}//end overview()
}//end class
