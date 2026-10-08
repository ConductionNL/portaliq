<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalManifestController;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * parent-pwa-installability: the manifest names the SAME portal
 * `PortalPageController::index()` resolves, and the service worker route
 * answers with the right content type and scope header.
 *
 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md
 */
class PortalManifestControllerTest extends TestCase {

	/**
	 * Throwaway app directories to remove after the test.
	 *
	 * @var list<string>
	 */
	private array $cleanUp = [];

	public function testManifestNamesTheResolvedOrganisation(): void {
		$controller = $this->controller(orgSlug: 'gemeente-x', resolved: ['organisationName' => 'Gemeente X']);

		$response = $controller->manifest();
		$manifest = json_decode((string)$response->getData(), true);

		$this->assertSame(expected: 'Gemeente X', actual: $manifest['name']);
		$this->assertStringContainsString(needle: 'org=gemeente-x', haystack: $manifest['start_url']);
		$this->assertSame(expected: 'standalone', actual: $manifest['display']);

	}//end testManifestNamesTheResolvedOrganisation()

	public function testManifestAnswersTheCorrectContentType(): void {
		$controller = $this->controller(orgSlug: '');

		$response = $controller->manifest();

		$this->assertSame(expected: 'application/manifest+json', actual: $this->headers(response: $response)['Content-Type']);

	}//end testManifestAnswersTheCorrectContentType()

	public function testManifestFallsBackToANeutralNameWhenNothingResolves(): void {
		$controller = $this->controller(orgSlug: '', resolved: ['organisationName' => '']);

		$manifest = json_decode((string)$controller->manifest()->getData(), true);

		$this->assertNotSame(expected: '', actual: $manifest['name']);
		$this->assertNotSame(expected: '', actual: $manifest['short_name']);

	}//end testManifestFallsBackToANeutralNameWhenNothingResolves()

	/**
	 * `short_name` falls back to the neutral default when the resolved name
	 * exceeds the platform's ~12-character guidance, rather than truncating
	 * it into something unreadable.
	 *
	 * @return void
	 */
	public function testShortNameFallsBackWhenTheResolvedNameIsTooLong(): void {
		$controller = $this->controller(orgSlug: '', resolved: ['organisationName' => 'Een heel erg lange gemeentenaam']);

		$manifest = json_decode((string)$controller->manifest()->getData(), true);

		$this->assertSame(expected: 'Portaal', actual: $manifest['short_name']);

	}//end testShortNameFallsBackWhenTheResolvedNameIsTooLong()

	/**
	 * A `?portal=` reference takes priority over `?org=` when building
	 * `start_url`, matching `PortalRuntimeConfigResolver::resolvePortal()`'s
	 * own precedence.
	 *
	 * @return void
	 */
	public function testStartUrlPrefersThePortalSlugOverOrg(): void {
		$controller = $this->controller(orgSlug: 'gemeente-x', portalSlug: 'a-specific-portal');

		$manifest = json_decode((string)$controller->manifest()->getData(), true);

		$this->assertStringContainsString(needle: 'portal=a-specific-portal', haystack: $manifest['start_url']);
		$this->assertStringNotContainsString(needle: 'org=gemeente-x', haystack: $manifest['start_url']);

	}//end testStartUrlPrefersThePortalSlugOverOrg()

	/**
	 * The installed app opens on the site with the same portal, and stays
	 * on it: `/portal` is being retired (site-reaches-portal-parity
	 * REQ-SRP-044), so an app installed today must not open on an address
	 * that will only redirect.
	 *
	 * @return void
	 */
	public function testStartUrlAndScopePointAtTheSite(): void {
		$controller = $this->controller(orgSlug: '', portalSlug: 'wilgenboom');

		$manifest = json_decode((string)$controller->manifest()->getData(), true);

		$this->assertSame(expected: '/index.php/apps/portaliq/route/portaliq.portalPage.site?portal=wilgenboom', actual: $manifest['start_url']);
		$this->assertSame(expected: '/index.php/apps/portaliq/route/portaliq.portalPage.site?', actual: $manifest['scope']);
		$this->assertStringStartsWith(prefix: strtok($manifest['scope'], '?'), string: $manifest['start_url']);

	}//end testStartUrlAndScopePointAtTheSite()

	public function testServiceWorkerAnswersTheAllowedScopeHeader(): void {
		$controller = $this->controller(orgSlug: '');

		$response = $controller->serviceWorker();
		$headers = $this->headers(response: $response);

		$this->assertSame(expected: 'application/javascript', actual: $headers['Content-Type']);
		$this->assertArrayHasKey(key: 'Service-Worker-Allowed', array: $headers);

	}//end testServiceWorkerAnswersTheAllowedScopeHeader()

	/**
	 * The worker's own fetch() follows the policy its script is served with.
	 * Nextcloud's empty default leaves connect-src out, so it fell back to
	 * default-src 'none' and every fetch of the worker failed: returning
	 * visitors got net::ERR_FAILED on /site. Same origin is allowed, nothing
	 * wider, and every other directive stays shut.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md#requirement-the-service-worker-caches-the-app-shell-and-never-the-api
	 */
	public function testTheWorkerMayFetchItsOwnOriginAndNothingElse(): void {
		$policy = $this->controller(orgSlug: '')->serviceWorker()->getContentSecurityPolicy()->buildPolicy();
		$directives = array_values(array_filter(array_map('trim', explode(';', $policy))));

		$this->assertContains(needle: "connect-src 'self'", haystack: $directives);
		$this->assertContains(needle: "default-src 'none'", haystack: $directives);
		$this->assertSame(
			expected: ["connect-src 'self'"],
			actual: array_values(array_filter($directives, static fn (string $d): bool => str_starts_with($d, 'connect-src'))),
			message: 'one connect-src, same origin only'
		);
		foreach ($directives as $directive) {
			$this->assertStringNotContainsString(needle: '*', haystack: $directive);
		}
	}//end testTheWorkerMayFetchItsOwnOriginAndNothingElse()

	/**
	 * The allowed scope is the route root the browser reached the worker on,
	 * which is the scope the site registers it with (src/site/lib/pwa.js
	 * turns `<root>/portal/api` into `<root>/`). An app installed in
	 * custom_apps/ is still routed under /apps/, so its file path is never
	 * the answer.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function workerAddresses(): array {
		return [
			'apps/ or custom_apps/, pretty URLs' => ['/apps/portaliq/portal/sw.js', '/apps/portaliq/'],
			'with index.php' => ['/index.php/apps/portaliq/portal/sw.js', '/index.php/apps/portaliq/'],
			'under a web root' => ['/nextcloud/index.php/apps/portaliq/portal/sw.js', '/nextcloud/index.php/apps/portaliq/'],
			'with a query' => ['/apps/portaliq/portal/sw.js?v=3', '/apps/portaliq/'],
		];
	}//end workerAddresses()

	/**
	 * The header names the scope the site registers the worker with.
	 *
	 * @param string $requestUri The address the browser asked for the worker on.
	 * @param string $scope The scope the site registers it with.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-service-worker-must-cache-the-site-shell-req-srp-045
	 */
	#[DataProvider('workerAddresses')]
	public function testTheAllowedScopeIsTheScopeTheSiteRegisters(string $requestUri, string $scope): void {
		$controller = $this->controller(orgSlug: '', requestUri: $requestUri);

		$headers = $this->headers(response: $controller->serviceWorker());

		$this->assertSame(expected: $scope, actual: $headers['Service-Worker-Allowed']);

	}//end testTheAllowedScopeIsTheScopeTheSiteRegisters()

	/**
	 * Reached some other way, the worker's own route gives the root; a route
	 * that is not the worker's falls back to the routed app path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-service-worker-must-cache-the-site-shell-req-srp-045
	 */
	public function testWithoutTheWorkerAddressTheRouteGivesTheScope(): void {
		$routed = $this->controller(orgSlug: '', requestUri: '', workerRoute: '/apps/portaliq/portal/sw.js');
		$this->assertSame(expected: '/apps/portaliq/', actual: $this->headers(response: $routed->serviceWorker())['Service-Worker-Allowed']);

		$unknown = $this->controller(orgSlug: '', requestUri: '/somewhere/else', workerRoute: '/somewhere/else');
		$this->assertSame(expected: '/apps/portaliq/', actual: $this->headers(response: $unknown->serviceWorker())['Service-Worker-Allowed']);

	}//end testWithoutTheWorkerAddressTheRouteGivesTheScope()

	public function testServiceWorkerServesTheSourceFilesContents(): void {
		$controller = $this->controller(orgSlug: '');

		$body = (string)$controller->serviceWorker()->getData();

		// Asserts against the real, checked-in source file rather than a
		// literal string, so this test still passes if serviceWorker.js's
		// own content changes for a legitimate reason.
		$this->assertSame(
			expected: (string)file_get_contents(dirname(__DIR__, 3) . '/src/shared/serviceWorker.js'),
			actual: $body
		);

	}//end testServiceWorkerServesTheSourceFilesContents()

	public function testAReleasePackageWithoutSourcesServesTheBuiltCopy(): void {
		$appRoot = $this->appRoot(files: ['js/portaliq-site-sw.js']);

		$this->assertSame(
			expected: $appRoot . '/js/portaliq-site-sw.js',
			actual: $this->serviceWorkerSourcePath(appRoot: $appRoot)
		);

	}//end testAReleasePackageWithoutSourcesServesTheBuiltCopy()

	public function testACheckoutWithSourcesServesTheSourceOverTheBuild(): void {
		$appRoot = $this->appRoot(files: ['js/portaliq-site-sw.js', 'src/shared/serviceWorker.js']);

		$this->assertSame(
			expected: $appRoot . '/src/shared/serviceWorker.js',
			actual: $this->serviceWorkerSourcePath(appRoot: $appRoot)
		);

	}//end testACheckoutWithSourcesServesTheSourceOverTheBuild()

	/**
	 * A throwaway app directory holding only the given files, removed at
	 * the end of the test.
	 *
	 * @param list<string> $files Paths relative to the app directory.
	 *
	 * @return string The app directory.
	 */
	private function appRoot(array $files): string {
		$appRoot = sys_get_temp_dir() . '/portaliq-sw-' . bin2hex(random_bytes(6));
		foreach ($files as $file) {
			@mkdir(dirname($appRoot . '/' . $file), 0o700, true);
			file_put_contents($appRoot . '/' . $file, '// worker');
		}

		$this->cleanUp[] = $appRoot;

		return $appRoot;
	}//end appRoot()

	/**
	 * The controller's service worker path for an app directory.
	 *
	 * @param string $appRoot The app directory.
	 *
	 * @return string
	 */
	private function serviceWorkerSourcePath(string $appRoot): string {
		$method = new ReflectionMethod(PortalManifestController::class, 'serviceWorkerSourcePath');

		return (string)$method->invoke($this->controller(orgSlug: ''), $appRoot);
	}//end serviceWorkerSourcePath()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		// Only what appRoot() created, and only under the system temp dir.
		foreach ($this->cleanUp as $appRoot) {
			@unlink($appRoot . '/js/portaliq-site-sw.js');
			@unlink($appRoot . '/src/shared/serviceWorker.js');
			@rmdir($appRoot . '/src/shared');
			@rmdir($appRoot . '/src');
			@rmdir($appRoot . '/js');
			@rmdir($appRoot);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The controller over doubles.
	 *
	 * @param string $orgSlug The `?org=` value.
	 * @param array<string, mixed> $resolved Overrides onto the neutral runtime config default.
	 * @param string $portalSlug The `?portal=` value.
	 *
	 * @return PortalManifestController
	 */
	private function controller(
		string $orgSlug,
		array $resolved = [],
		string $portalSlug = '',
		string $requestUri = '/index.php/apps/portaliq/portal/sw.js',
		string $workerRoute = '/index.php/apps/portaliq/portal/sw.js',
	): PortalManifestController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn($requestUri);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($orgSlug, $portalSlug) {
				if ($key === 'org') {
					return $orgSlug;
				}

				if ($key === 'portal') {
					return $portalSlug;
				}

				return $default;
			}
		);

		$default = [
			'organisationName' => 'Portaliq',
			'organisationSlug' => '',
			'theme' => 'default',
			'logo' => null,
			'oidcProviders' => [],
			'featureFlags' => [],
			'allowedEmbedOrigins' => [],
			'apiBase' => '/index.php/apps/portaliq/portal/api',
			'audience' => 'supplier',
			'locale' => 'nl',
		];

		$configResolver = $this->createMock(PortalRuntimeConfigResolver::class);
		$configResolver->method('resolvePortal')->willReturn(null);
		$configResolver->method('runtimeConfigFor')->willReturn(array_merge($default, $resolved));

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnCallback(
				static fn (string $name, array $params = []): string => match ($name) {
					'portaliq.portalManifest.serviceWorker' => $workerRoute,
					default => ('/index.php/apps/portaliq/route/' . $name . '?' . http_build_query($params)),
				}
			);
		$urlGenerator->method('imagePath')->willReturn('/index.php/apps/portaliq/img/app.svg');
		// Where the app's FILES are served: an app installed in custom_apps/.
		$urlGenerator->method('linkTo')->willReturn('/custom_apps/portaliq/');

		return new PortalManifestController($request, $configResolver, $urlGenerator);
	}//end controller()

	/**
	 * The headers a response was GIVEN, read off the object rather than
	 * through `getHeaders()`, which merges in platform defaults and needs
	 * the Nextcloud runtime for the request id (same pattern as
	 * `TrafficControllerTest::headers()`).
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()

}//end class
