<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalManifestController;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * parent-pwa-installability: the manifest names the SAME portal
 * `PortalPageController::index()` resolves, and the service worker route
 * answers with the right content type and scope header.
 *
 * @spec openspec/changes/parent-pwa-installability/specs/parent-pwa-installability/spec.md
 */
class PortalManifestControllerTest extends TestCase {

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

	public function testServiceWorkerAnswersTheAllowedScopeHeader(): void {
		$controller = $this->controller(orgSlug: '');

		$response = $controller->serviceWorker();
		$headers = $this->headers(response: $response);

		$this->assertSame(expected: 'application/javascript', actual: $headers['Content-Type']);
		$this->assertArrayHasKey(key: 'Service-Worker-Allowed', array: $headers);

	}//end testServiceWorkerAnswersTheAllowedScopeHeader()

	public function testServiceWorkerServesTheSourceFilesContents(): void {
		$controller = $this->controller(orgSlug: '');

		$body = (string)$controller->serviceWorker()->getData();

		// Asserts against the real, checked-in source file rather than a
		// literal string, so this test still passes if serviceWorker.js's
		// own content changes for a legitimate reason.
		$this->assertSame(
			expected: (string)file_get_contents(dirname(__DIR__, 3) . '/src/portal/serviceWorker.js'),
			actual: $body
		);

	}//end testServiceWorkerServesTheSourceFilesContents()

	/**
	 * The controller over doubles.
	 *
	 * @param string $orgSlug The `?org=` value.
	 * @param array<string, mixed> $resolved Overrides onto the neutral runtime config default.
	 * @param string $portalSlug The `?portal=` value.
	 *
	 * @return PortalManifestController
	 */
	private function controller(string $orgSlug, array $resolved = [], string $portalSlug = ''): PortalManifestController {
		$request = $this->createMock(IRequest::class);
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
				static fn (string $name, array $params = []): string => ('/index.php/apps/portaliq/route/' . $name . '?' . http_build_query($params))
			);
		$urlGenerator->method('imagePath')->willReturn('/index.php/apps/portaliq/img/app.svg');
		$urlGenerator->method('linkTo')->willReturn('/index.php/apps/portaliq/');

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
