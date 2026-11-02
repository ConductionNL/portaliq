<?php

/**
 * BundledSiteController contract tests.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\BundledSiteController;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Wire contract for `GET /woo` (`bundledSite#serve`) and `GET /woo/{path}`
 * (`bundledSite#servePath`), which serve the `woo` bundle.
 *
 * Both are `#[PublicPage]`, so an ANONYMOUS caller controls `{path}` — and the
 * route requirement is `'path' => '.+'`, which accepts slashes. Path traversal
 * is therefore the contract worth pinning: the controller must never serve a
 * byte from outside the bundled `woo/` root, and must fall back to the SPA
 * entry instead.
 *
 * These tests build a real throwaway bundle on disk and re-root the controller
 * at it (the production root is derived from `__DIR__` at construction time and
 * does not exist in a source checkout — the SPA is a build artefact).
 *
 * They assert on the BYTES returned. That is what makes a traversal regression
 * visible: a controller that happily served `/etc/passwd` would still return a
 * perfectly well-formed `DataDisplayResponse`, so asserting the response TYPE
 * would prove nothing at all.
 *
 * SCOPE NOTE — the cache-header half of this contract (`index.html` and
 * `runtime-config.js` must not be cached, hash-named assets may be) is
 * deliberately NOT asserted here. `Response::cacheFor()` and
 * `Response::getHeaders()` resolve services through `OCP\Server`, which needs
 * the Nextcloud runtime. Every assertion in this file runs without it, so each
 * one is verifiable in isolation rather than green only where the runtime
 * happens to be present.
 *
 * @covers \OCA\Portaliq\Controller\BundledSiteController
 */
class BundledSiteControllerTest extends TestCase {

	/**
	 * Temporary bundle root standing in for the built SPA.
	 *
	 * @var string
	 */
	private string $bundle;

	/**
	 * The controller under test, rooted at the temporary bundle.
	 *
	 * @var BundledSiteController
	 */
	private BundledSiteController $controller;

	/**
	 * A file OUTSIDE the bundle that traversal must never reach.
	 *
	 * @var string
	 */
	private string $secretOutside;

	/**
	 * Build a throwaway bundle plus a secret file one level above its root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . '/pq-woo-' . bin2hex(random_bytes(6));
		mkdir($base . '/woo/assets', 0777, true);

		$this->bundle = $base . '/woo';
		$this->secretOutside = $base . '/secret.txt';

		file_put_contents($this->bundle . '/index.html', '<!doctype html><title>WOO SPA</title>');
		file_put_contents($this->bundle . '/assets/app.a1b2c3.js', 'console.log(1)');
		file_put_contents($this->secretOutside, 'TOP-SECRET');

		// A sibling whose name starts with the bundle's, and a directory that
		// is not a bundle but does hold an index.html.
		mkdir($base . '/woo-old');
		file_put_contents($base . '/woo-old/secret.txt', 'TOP-SECRET');
		mkdir($base . '/lib');
		file_put_contents($base . '/lib/index.html', 'TOP-SECRET');

		$this->controller = new BundledSiteController('portaliq', $this->createMock(IRequest::class));

		$root = (new ReflectionClass(BundledSiteController::class))->getProperty('appRoot');
		$root->setValue($this->controller, realpath($base));
	}//end setUp()

	/**
	 * Remove the throwaway bundle.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ([
			$this->bundle . '/assets/app.a1b2c3.js',
			$this->bundle . '/index.html',
			$this->secretOutside,
			dirname($this->bundle) . '/woo-old/secret.txt',
			dirname($this->bundle) . '/lib/index.html',
		] as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

		@rmdir($this->bundle . '/assets');
		@rmdir($this->bundle);
		@rmdir(dirname($this->bundle) . '/woo-old');
		@rmdir(dirname($this->bundle) . '/lib');
		@rmdir(dirname($this->bundle));

		parent::tearDown();
	}//end tearDown()

	/**
	 * Read the rendered bytes out of a response.
	 *
	 * @param DataDisplayResponse $response The response to read.
	 *
	 * @return string
	 */
	private function bytes(DataDisplayResponse $response): string {
		return (string)$response->render();
	}//end bytes()

	/**
	 * `/woo` serves the SPA entry.
	 *
	 * @return void
	 */
	public function testServeReturnsTheSpaEntry(): void {
		$response = $this->controller->serve(bundle: 'woo');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertStringContainsString('WOO SPA', $this->bytes($response));
	}//end testServeReturnsTheSpaEntry()

	/**
	 * An unknown sub-path is a client-side route, so it falls back to the SPA
	 * entry rather than 404ing.
	 *
	 * @return void
	 */
	public function testServePathFallsBackToTheSpaForClientRoutes(): void {
		$response = $this->controller->servePath(bundle: 'woo', path: 'requests/2026/42');

		$this->assertStringContainsString('WOO SPA', $this->bytes($response));
	}//end testServePathFallsBackToTheSpaForClientRoutes()

	/**
	 * Traversal above the bundle root NEVER serves the outside file; it falls
	 * back to the SPA entry. Every one of these is reachable by an anonymous
	 * caller, because the route requirement is `.+`.
	 *
	 * @param string $path The hostile sub-path.
	 *
	 * @return void
	 *
	 * @dataProvider traversalProvider
	 */
	public function testServePathRefusesToEscapeTheBundleRoot(string $path): void {
		$body = $this->bytes($this->controller->servePath(bundle: 'woo', path: $path));

		$this->assertStringNotContainsString('TOP-SECRET', $body, 'traversal escaped the bundle root');
		$this->assertStringContainsString('WOO SPA', $body, 'traversal should fall back to the SPA entry');
	}//end testServePathRefusesToEscapeTheBundleRoot()

	/**
	 * Hostile sub-paths an anonymous caller can put in `/woo/{path}`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function traversalProvider(): array {
		return [
			'dot-dot to sibling file' => ['../secret.txt'],
			'nested dot-dot' => ['assets/../../secret.txt'],
			'backslash separators' => ['..\\secret.txt'],
			'leading slash + dot-dot' => ['/../secret.txt'],
			'absolute path' => ['/etc/hostname'],
			'sibling directory that shares the prefix' => ['../woo-old/secret.txt'],
		];
	}//end traversalProvider()

	/**
	 * An empty `{path}` (the route's own default) is the SPA entry, never a
	 * directory listing or an error.
	 *
	 * @return void
	 */
	public function testServePathWithTheRouteDefaultReturnsTheSpaEntry(): void {
		$this->assertStringContainsString(
			'WOO SPA',
			$this->bytes($this->controller->servePath(bundle: 'woo', path: ''))
		);
	}//end testServePathWithTheRouteDefaultReturnsTheSpaEntry()

	/**
	 * A real bundled asset is served byte for byte. Runs in CI, where the
	 * Nextcloud runtime is present.
	 *
	 * @return void
	 */
	public function testServePathReturnsABundledAsset(): void {
		if (class_exists(\OC::class) === false) {
			// An asset is cached, and Response::cacheFor() resolves a service
			// through OCP\Server (see the scope note on the class).
			$this->markTestSkipped('Needs the Nextcloud runtime: a served asset sets cache headers.');
		}

		$this->assertSame(
			'console.log(1)',
			$this->bytes($this->controller->servePath(bundle: 'woo', path: 'assets/app.a1b2c3.js'))
		);
	}//end testServePathReturnsABundledAsset()

	/**
	 * `bundle` is a request parameter, so a caller may try to name another
	 * directory. Only a bundle this app ships is served: anything else is a
	 * 404 with none of the directory's bytes, even when it holds an
	 * index.html.
	 *
	 * @param string $bundle The bundle name the caller sends.
	 *
	 * @dataProvider unknownBundleProvider
	 *
	 * @return void
	 */
	public function testABundleThisAppDoesNotShipIsNotFound(string $bundle): void {
		foreach ([$this->controller->serve(bundle: $bundle), $this->controller->servePath(bundle: $bundle, path: 'index.html')] as $response) {
			$this->assertSame(404, $response->getStatus());
			$this->assertStringNotContainsString('TOP-SECRET', $this->bytes($response));
			$this->assertStringNotContainsString('WOO SPA', $this->bytes($response));
		}
	}//end testABundleThisAppDoesNotShipIsNotFound()

	/**
	 * Bundle names that must not be served.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unknownBundleProvider(): array {
		return [
			'no bundle named' => [''],
			'a real directory that is not a bundle' => ['lib'],
			'the app root' => ['.'],
			'the parent' => ['..'],
			'a path through the bundle' => ['woo/../lib'],
			'another case' => ['WOO'],
		];
	}//end unknownBundleProvider()
}//end class
