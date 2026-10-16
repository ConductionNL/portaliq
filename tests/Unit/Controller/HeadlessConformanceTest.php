<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * portal-headless-content-api task 4: every content route is public, declares
 * it with the attribute form, and is rate limited, and everything the built-in
 * portal reads of the content API is a routed public path. The test reads
 * routes.php and the portal's sources, nothing else of Portaliq.
 *
 * @spec openspec/changes/portal-headless-content-api/tasks.md#task-4
 */
class HeadlessConformanceTest extends TestCase {
	/**
	 * The routes whose controller name starts with "content".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contentRoutes(): array {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];

		return array_values(array_filter($routes, static fn (array $r): bool => str_starts_with((string)$r['name'], 'content')));
	}//end contentRoutes()

	public function testEveryContentRouteIsPublicAndRateLimited(): void {
		$routes = $this->contentRoutes();
		$this->assertNotSame([], $routes);

		foreach ($routes as $route) {
			[$controller, $action] = explode('#', (string)$route['name']);
			$class  = 'OCA\\Portaliq\\Controller\\' . ucfirst($controller) . 'Controller';
			$method = new ReflectionMethod($class, $action);

			$this->assertCount(1, $method->getAttributes(PublicPage::class), $route['name'] . ' declares PublicPage');
			$this->assertCount(1, $method->getAttributes(AnonRateLimit::class), $route['name'] . ' is rate limited, cached or not');
			$this->assertSame('GET', $route['verb'], $route['name'] . ' is read-only');
		}
	}//end testEveryContentRouteIsPublicAndRateLimited()

	public function testTheContractRoutesAreAllThere(): void {
		$names = array_map(static fn (array $r): string => (string)$r['name'], $this->contentRoutes());

		foreach (['content#site', 'content#menus', 'content#pages', 'content#page', 'content#glossary', 'contentMedia#show'] as $capability) {
			$this->assertContains($capability, $names, $capability . ' is part of the headless contract');
		}
	}//end testTheContractRoutesAreAllThere()

	public function testEverythingThePortalReadsOfTheContentApiIsARoutedPath(): void {
		$urls = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if (in_array($file->getExtension(), ['js', 'vue'], true) === false) {
				continue;
			}

			preg_match_all('#/api/content/[a-z][a-z/-]*[a-z]#', (string)file_get_contents($file->getPathname()), $found);
			foreach ($found[0] as $url) {
				$urls[$url] = $file->getFilename();
			}
		}

		$this->assertNotSame([], $urls, 'the portal reads the content API');
		$routed = array_map(static fn (array $r): string => (string)$r['url'], $this->contentRoutes());
		foreach ($urls as $url => $reader) {
			$this->assertContains($url, $routed, $reader . ' reads ' . $url . ' which the public API does not route');
		}
	}//end testEverythingThePortalReadsOfTheContentApiIsARoutedPath()
}//end class
