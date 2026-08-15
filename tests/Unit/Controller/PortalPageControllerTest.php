<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalPageController;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * portal-white-label-runtime-config: the shell renders through
 * TemplateResponse::RENDER_AS_PUBLIC with the resolved runtime config passed
 * as a template param, and the CSP's frame-ancestors is built from the
 * resolved Organisation's allowed embed origins — 'none' when empty, NEVER
 * the previous hard-coded '*'. catchAll() renders through the same index()
 * path so every portal URL (not just `/portal`) carries the config.
 *
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.1
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.2
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#3.3
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
 */
class PortalPageControllerTest extends TestCase {

	public function testIndexRendersPortalTemplateAsPublic(): void {
		$controller = $this->controller(orgSlug: '');
		$response = $controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(TemplateResponse::RENDER_AS_PUBLIC, $response->getRenderAs());

	}//end testIndexRendersPortalTemplateAsPublic()

	public function testNoAllowedEmbedOriginsYieldsFrameAncestorsNone(): void {
		$controller = $this->controller(orgSlug: '', resolved: ['allowedEmbedOrigins' => []]);
		$response = $controller->index();

		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString("frame-ancestors 'none';", $policy);
		$this->assertStringNotContainsString('frame-ancestors *;', $policy);

	}//end testNoAllowedEmbedOriginsYieldsFrameAncestorsNone()

	public function testConfiguredAllowedEmbedOriginsAreAppliedNeverWildcard(): void {
		$controller = $this->controller(
			orgSlug: 'gemeente-x',
			resolved: ['allowedEmbedOrigins' => ['https://gemeente-x.example']]
		);
		$response = $controller->index();

		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString('frame-ancestors https://gemeente-x.example;', $policy);
		$this->assertStringNotContainsString('frame-ancestors *;', $policy);
		// The 'self' default (allowed BEFORE any tenant configuration is
		// resolved) must not silently persist alongside a configured origin.
		$this->assertStringNotContainsString("frame-ancestors 'self'", $policy);

	}//end testConfiguredAllowedEmbedOriginsAreAppliedNeverWildcard()

	public function testCatchAllDelegatesToIndexForDistinctPaths(): void {
		$controller = $this->controller(orgSlug: '');

		foreach (['contracts/123', 'invoices/456'] as $path) {
			$response = $controller->catchAll($path);
			$this->assertInstanceOf(TemplateResponse::class, $response);
			$this->assertSame(TemplateResponse::RENDER_AS_PUBLIC, $response->getRenderAs());
		}

	}//end testCatchAllDelegatesToIndexForDistinctPaths()

	public function testIndexPassesTheFirstAcceptLanguageTagToTheResolver(): void {
		$received = null;
		$resolver = $this->createMock(PortalOrganisationConfigService::class);
		$resolver->method('resolve')->willReturnCallback(
			function (string $orgSlug, string $locale = 'nl') use (&$received) {
				$received = $locale;
				return ['allowedEmbedOrigins' => []];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'org' ? '' : $default)
		);
		$request->method('getHeader')->willReturnMap([['Accept-Language', 'en-US,en;q=0.9,nl;q=0.8']]);

		(new PortalPageController($request, $resolver, $this->createMock(IURLGenerator::class)))->index();

		$this->assertSame('en-US', $received);

	}//end testIndexPassesTheFirstAcceptLanguageTagToTheResolver()

	private function controller(string $orgSlug, array $resolved = []): PortalPageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'org' ? $orgSlug : $default)
		);
		$request->method('getHeader')->willReturn('');

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

		$resolver = $this->createMock(PortalOrganisationConfigService::class);
		$resolver->method('resolve')->willReturn(array_merge($default, $resolved));

		// The site renderer (`site()`) needs a URL generator to hand the
		// content API base to the client. Returning the real route shape here
		// rather than an empty string keeps the assertion in
		// testSiteRendersSiteTemplateAsPublic meaningful.
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturn('/index.php/apps/portaliq/api/content/site');

		return new PortalPageController($request, $resolver, $urlGenerator);
	}//end controller()

}//end class
